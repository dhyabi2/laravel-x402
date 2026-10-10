<?php

declare(strict_types=1);

use Requestway\X402\Core\Errors\ErrorCode;
use Requestway\X402\Core\Exceptions\MalformedPaymentException;
use Requestway\X402\Core\Messages\PaymentRequired;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Messages\SupportedKind;
use Requestway\X402\Core\Messages\SupportedResponse;
use Requestway\X402\Core\Messages\VerifyResponse;

function requirements(array $overrides = []): PaymentRequirements
{
    return PaymentRequirements::fromArray(array_merge([
        'scheme' => 'exact',
        'network' => 'eip155:84532',
        'amount' => '10000',
        'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
        'payTo' => '0x209693Bc6afc0C5328bA36FaF03C514EF312287C',
        'maxTimeoutSeconds' => 60,
        'extra' => ['name' => 'USDC', 'version' => '2'],
    ], $overrides));
}

it('accepts an identical echo of the advertised requirements', function (): void {
    expect(requirements()->isSatisfiedBy(requirements()))->toBeTrue();
});

it('ignores EIP-55 checksum casing on addresses', function (): void {
    $echoed = requirements([
        'asset' => strtolower('0x036CbD53842c5426634e7929541eC2318f3dCF7e'),
        'payTo' => strtoupper('0x209693Bc6afc0C5328bA36FaF03C514EF312287C'),
    ]);

    expect(requirements()->isSatisfiedBy($echoed))->toBeTrue();
});

it('accepts an overpayment but never an underpayment', function (): void {
    expect(requirements()->isSatisfiedBy(requirements(['amount' => '10001'])))->toBeTrue()
        ->and(requirements()->isSatisfiedBy(requirements(['amount' => '9999'])))->toBeFalse()
        ->and(requirements()->isUnderpaidBy(requirements(['amount' => '9999'])))->toBeTrue()
        ->and(requirements()->isUnderpaidBy(requirements(['amount' => '10000'])))->toBeFalse();
});

it('rejects an echo that changes a core term', function (array $overrides): void {
    expect(requirements()->isSatisfiedBy(requirements($overrides)))->toBeFalse();
})->with([
    'scheme' => [['scheme' => 'upto']],
    'network' => [['network' => 'eip155:8453']],
    'asset' => [['asset' => '0x0000000000000000000000000000000000000001']],
    'payTo' => [['payTo' => '0x0000000000000000000000000000000000000002']],
    'timeout' => [['maxTimeoutSeconds' => 120]],
]);

it('lets a client add extra keys but not change the ones the server declared', function (): void {
    $additive = requirements(['extra' => ['name' => 'USDC', 'version' => '2', 'assetTransferMethod' => 'eip3009']]);
    $changed = requirements(['extra' => ['name' => 'USDC', 'version' => '3']]);
    $missing = requirements(['extra' => ['name' => 'USDC']]);

    expect(requirements()->isSatisfiedBy($additive))->toBeTrue()
        ->and(requirements()->isSatisfiedBy($changed))->toBeFalse()
        ->and(requirements()->isSatisfiedBy($missing))->toBeFalse();
});

it('compares nested extra objects', function (): void {
    $server = requirements(['extra' => ['limits' => ['max' => 5]]]);

    expect($server->isSatisfiedBy(requirements(['extra' => ['limits' => ['max' => 5, 'min' => 1]]])))->toBeTrue()
        ->and($server->isSatisfiedBy(requirements(['extra' => ['limits' => ['max' => 6]]])))->toBeFalse()
        ->and($server->isSatisfiedBy(requirements(['extra' => ['limits' => 'nope']])))->toBeFalse();
});

it('defaults the reserved extra keys the way the spec does', function (): void {
    expect(requirements()->assetTransferMethod())->toBe('eip3009')
        ->and(requirements()->paymentFlow())->toBe('authorization')
        ->and(requirements(['extra' => ['assetTransferMethod' => 'permit2']])->assetTransferMethod())->toBe('permit2')
        ->and(requirements(['extra' => ['paymentFlow' => 'upfront']])->paymentFlow())->toBe('upfront');
});

it('reads the v1 maxAmountRequired field name', function (): void {
    $parsed = PaymentRequirements::fromArray([
        'scheme' => 'exact',
        'network' => 'base-sepolia',
        'maxAmountRequired' => '10000',
        'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
        'payTo' => '0x209693Bc6afc0C5328bA36FaF03C514EF312287C',
        'maxTimeoutSeconds' => '60',
    ]);

    expect($parsed->amount)->toBe('10000')->and($parsed->maxTimeoutSeconds)->toBe(60);
});

it('rejects requirements that are missing or malformed', function (array $data): void {
    expect(fn (): PaymentRequirements => PaymentRequirements::fromArray($data))
        ->toThrow(MalformedPaymentException::class);
})->with([
    'no amount' => [['scheme' => 'exact', 'network' => 'eip155:1', 'asset' => 'a', 'payTo' => 'b', 'maxTimeoutSeconds' => 60]],
    'float amount' => [['scheme' => 'exact', 'network' => 'eip155:1', 'amount' => '1.5', 'asset' => 'a', 'payTo' => 'b', 'maxTimeoutSeconds' => 60]],
    'no scheme' => [['network' => 'eip155:1', 'amount' => '1', 'asset' => 'a', 'payTo' => 'b', 'maxTimeoutSeconds' => 60]],
    'blank network' => [['scheme' => 'exact', 'network' => ' ', 'amount' => '1', 'asset' => 'a', 'payTo' => 'b', 'maxTimeoutSeconds' => 60]],
    'no timeout' => [['scheme' => 'exact', 'network' => 'eip155:1', 'amount' => '1', 'asset' => 'a', 'payTo' => 'b']],
]);

it('omits an empty extra object from the wire', function (): void {
    expect(requirements(['extra' => []])->toArray())->not->toHaveKey('extra');
});

it('clamps resource metadata to the limits in the spec', function (): void {
    $resource = new ResourceInfo(
        url: 'https://api.test/data',
        serviceName: str_repeat('a', 50),
        tags: ['one', 'two', 'three', 'four', 'five', 'six'],
        iconUrl: 'https://api.test/'.str_repeat('i', 3000),
    );

    $array = $resource->toArray();

    expect($array['serviceName'])->toHaveLength(32)
        ->and($array['tags'])->toHaveCount(5)
        ->and($array['iconUrl'])->toHaveLength(2048);
});

it('strips non-ASCII from the service name, which the spec requires to be printable ASCII', function (): void {
    $array = (new ResourceInfo(url: 'https://api.test', serviceName: 'Café — Data', tags: ['piñata']))->toArray();

    expect($array['serviceName'])->toBe('Caf  Data')
        ->and($array['tags'][0])->toBe('piata');
});

it('omits optional resource fields when they are not set', function (): void {
    expect((new ResourceInfo('https://api.test'))->toArray())->toBe(['url' => 'https://api.test']);
});

it('round-trips resource info', function (): void {
    $data = [
        'url' => 'https://api.test/data',
        'description' => 'Data',
        'mimeType' => 'application/json',
        'serviceName' => 'Test',
        'tags' => ['a', 'b'],
        'iconUrl' => 'https://api.test/icon.png',
    ];

    expect(ResourceInfo::fromArray($data)->toArray())->toBe($data);
});

it('replaces the resource url without touching anything else', function (): void {
    $resource = (new ResourceInfo('https://a.test', 'Desc'))->withUrl('https://b.test');

    expect($resource->url)->toBe('https://b.test')->and($resource->description)->toBe('Desc');
});

it('builds a PaymentRequired and replaces its error message', function (): void {
    $required = PaymentRequired::fromArray(fixture('payment_required'));

    expect($required->error)->toBe('PAYMENT-SIGNATURE header is required')
        ->and($required->withError('Pay up')->error)->toBe('Pay up')
        ->and($required->withError(null)->toArray())->not->toHaveKey('error')
        ->and($required->first())->toBeInstanceOf(PaymentRequirements::class)
        ->and((new PaymentRequired(new ResourceInfo('https://a.test'), []))->first())->toBeNull();
});

it('reads a verify response and its optional fields', function (): void {
    $valid = VerifyResponse::fromArray(['isValid' => true, 'payer' => '0xabc']);
    $invalid = VerifyResponse::fromArray(['isValid' => false, 'invalidReason' => 'insufficient_funds']);

    expect($valid->isValid)->toBeTrue()
        ->and($valid->payer)->toBe('0xabc')
        ->and($valid->toArray())->toBe(['isValid' => true, 'payer' => '0xabc'])
        ->and($invalid->isValid)->toBeFalse()
        ->and($invalid->reasonMessage())->toBe(ErrorCode::InsufficientFunds->message());
});

it('builds verify responses for both outcomes', function (): void {
    expect(VerifyResponse::valid('0xabc')->isValid)->toBeTrue()
        ->and(VerifyResponse::invalid(ErrorCode::InvalidNetwork)->invalidReason)->toBe('invalid_network')
        ->and(VerifyResponse::invalid('custom_reason')->reasonMessage())->toContain('custom_reason');
});

it('treats a broadcast-but-unconfirmed settlement as non-terminal', function (): void {
    $pending = SettleResponse::fromArray([
        'success' => false,
        'errorReason' => 'settlement_pending',
        'transaction' => '0xabc',
        'network' => 'eip155:84532',
    ]);

    $withoutHash = SettleResponse::fromArray([
        'success' => false,
        'errorReason' => 'settlement_pending',
        'transaction' => '',
        'network' => 'eip155:84532',
    ]);

    expect($pending->isPending())->toBeTrue()
        // The spec requires a non-empty transaction with settlement_pending;
        // without one there is nothing to reconcile, so it is a hard failure.
        ->and($withoutHash->isPending())->toBeFalse()
        ->and(ErrorCode::SettlementPending->isTerminal())->toBeFalse()
        ->and(ErrorCode::InsufficientFunds->isTerminal())->toBeTrue();
});

it('builds settlement responses for both outcomes', function (): void {
    $settled = SettleResponse::settled('eip155:84532', '0xtx', '0xpayer', '10000');
    $failed = SettleResponse::failed('eip155:84532', ErrorCode::InsufficientFunds, '0xpayer');

    expect($settled->toArray())->toBe([
        'success' => true,
        'transaction' => '0xtx',
        'network' => 'eip155:84532',
        'payer' => '0xpayer',
        'amount' => '10000',
    ])
        ->and($failed->success)->toBeFalse()
        ->and($failed->transaction)->toBe('')
        ->and($failed->errorReason)->toBe('insufficient_funds');
});

it('matches facilitator support including namespace wildcards', function (): void {
    $supported = SupportedResponse::fromArray([
        'kinds' => [
            ['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:*'],
            ['x402Version' => 2, 'scheme' => 'upto', 'network' => 'solana:abc'],
        ],
        'extensions' => ['bazaar', 42],
        'signers' => ['eip155:*' => ['0xsigner'], 'bad' => 'nope'],
    ]);

    expect($supported->supports('exact', 'eip155:8453'))->toBeTrue()
        ->and($supported->supports('exact', 'eip155:84532'))->toBeTrue()
        ->and($supported->supports('exact', 'solana:abc'))->toBeFalse()
        ->and($supported->supports('upto', 'solana:abc'))->toBeTrue()
        ->and($supported->supports('exact', 'eip155:8453', 1))->toBeFalse()
        ->and($supported->extensions)->toBe(['bazaar'])
        ->and($supported->signers)->toBe(['eip155:*' => ['0xsigner']])
        ->and($supported->networks())->toBe(['eip155:*', 'solana:abc']);
});

it('serializes a supported kind', function (): void {
    $supported = new SupportedResponse([new SupportedKind(2, 'exact', 'eip155:8453')]);

    expect($supported->toArray()['kinds'][0])->toBe([
        'x402Version' => 2,
        'scheme' => 'exact',
        'network' => 'eip155:8453',
    ]);
});

it('keeps scheme-specific extra on a supported kind', function (): void {
    $kind = SupportedKind::fromArray(['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:1', 'extra' => ['a' => 1]]);

    expect($kind->toArray())->toHaveKey('extra');
});

it('describes every error code in the spec', function (): void {
    foreach (ErrorCode::cases() as $code) {
        expect($code->message())->toBeString()->not->toBeEmpty();
    }

    expect(ErrorCode::describe(null))->toBe('The payment was rejected.')
        ->and(ErrorCode::describe(''))->toBe('The payment was rejected.')
        ->and(ErrorCode::describe('invalid_network'))->toBe(ErrorCode::InvalidNetwork->message())
        ->and(ErrorCode::tryFromReason(null))->toBeNull()
        ->and(ErrorCode::tryFromReason('invalid_network'))->toBe(ErrorCode::InvalidNetwork);
});
