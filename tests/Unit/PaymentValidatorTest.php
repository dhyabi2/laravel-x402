<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Errors\ErrorCode;
use HydraLabs\X402\Core\Messages\PaymentPayload;
use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Core\Messages\ResourceInfo;
use HydraLabs\X402\Core\Protocol\Version;
use HydraLabs\X402\Core\Support\FrozenClock;
use HydraLabs\X402\Core\Testing\PaymentPayloadFactory;
use HydraLabs\X402\Core\Validation\PaymentValidator;

const NOW = 1740672100;

function paymentValidator(): PaymentValidator
{
    return new PaymentValidator(new FrozenClock(NOW));
}

function payloadFor(PaymentRequirements $requirements, array $overrides = [], ?ResourceInfo $resource = null, int $version = Version::V2): PaymentPayload
{
    return (new PaymentPayloadFactory(new FrozenClock(NOW)))->exact(
        requirements: $requirements,
        resource: $resource,
        overrides: $overrides,
        version: $version,
    );
}

function reqs(array $overrides = []): PaymentRequirements
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

it('matches a payload against the requirement it paid for', function (): void {
    $requirements = reqs();

    [$matched, $failure] = paymentValidator()->match(payloadFor($requirements), [$requirements]);

    expect($matched)->not->toBeNull()->and($failure)->toBeNull();
});

it('picks the right offer when several networks are accepted', function (): void {
    $base = reqs(['network' => 'eip155:8453', 'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913']);
    $sepolia = reqs();

    [$matched] = paymentValidator()->match(payloadFor($sepolia), [$base, $sepolia]);

    expect($matched->network)->toBe('eip155:84532');
});

it('reports a route that advertises nothing as a server error', function (): void {
    [$matched, $failure] = paymentValidator()->match(payloadFor(reqs()), []);

    expect($matched)->toBeNull()
        ->and($failure->code)->toBe(ErrorCode::InvalidPaymentRequirements)
        ->and($failure->status)->toBe(500);
});

it('explains why a payload matched no offer', function (array $overrides, ErrorCode $expected): void {
    [$matched, $failure] = paymentValidator()->match(payloadFor(reqs($overrides)), [reqs()]);

    expect($matched)->toBeNull()->and($failure->code)->toBe($expected);
})->with([
    'wrong network' => [['network' => 'eip155:8453'], ErrorCode::InvalidNetwork],
    'wrong scheme' => [['scheme' => 'upto'], ErrorCode::InvalidScheme],
    'wrong asset' => [['asset' => '0x0000000000000000000000000000000000000001'], ErrorCode::InvalidPayload],
    'wrong payTo' => [['payTo' => '0x0000000000000000000000000000000000000002'], ErrorCode::RecipientMismatch],
    'underpaid' => [['amount' => '9999'], ErrorCode::ValueMismatch],
    'wrong timeout' => [['maxTimeoutSeconds' => 600], ErrorCode::InvalidPayload],
]);

it('matches a v1 payload on scheme and network alone', function (): void {
    $requirements = reqs();

    [$matched, $failure] = paymentValidator()->match(
        payloadFor($requirements, version: Version::V1),
        [$requirements]
    );

    expect($matched)->not->toBeNull()->and($failure)->toBeNull();
});

it('rejects a v1 payload for a scheme or network the route does not accept', function (): void {
    [$matched, $failure] = paymentValidator()->match(
        payloadFor(reqs(['network' => 'eip155:1']), version: Version::V1),
        [reqs()]
    );

    expect($matched)->toBeNull()->and($failure->code)->toBe(ErrorCode::InvalidNetwork);
});

it('accepts a payload with no resource echo', function (): void {
    expect(paymentValidator()->validate(payloadFor(reqs()), reqs(), ['https://api.test/report']))->toBeNull();
});

it('accepts a resource echo that matches the request', function (string $claimed, string $actual): void {
    $payload = payloadFor(reqs(), resource: new ResourceInfo($claimed));

    expect(paymentValidator()->validate($payload, reqs(), [$actual]))->toBeNull();
})->with([
    'identical' => ['https://api.test/report', 'https://api.test/report'],
    'trailing slash' => ['https://api.test/report/', 'https://api.test/report'],
    'host casing' => ['https://API.TEST/report', 'https://api.test/report'],
    'default port' => ['https://api.test:443/report', 'https://api.test/report'],
    'query string' => ['https://api.test/report?a=1', 'https://api.test/report?a=1'],
]);

it('rejects a payload authorized for a different resource', function (string $claimed): void {
    $payload = payloadFor(reqs(), resource: new ResourceInfo($claimed));

    $failure = paymentValidator()->validate($payload, reqs(), ['https://api.test/report']);

    expect($failure)->not->toBeNull()
        ->and($failure->code)->toBe(ErrorCode::InvalidPayload)
        ->and($failure->message)->toContain('different resource');
})->with([
    'other path' => ['https://api.test/cheap'],
    'other host' => ['https://evil.test/report'],
    'other port' => ['https://api.test:8443/report'],
    'other query' => ['https://api.test/report?a=2'],
    'not a url' => ['nonsense'],
]);

it('rejects a payload whose scheme or network drifts from its matched requirement', function (): void {
    $payload = payloadFor(reqs(['network' => 'eip155:8453']));

    expect(paymentValidator()->validate($payload, reqs(), [])->code)->toBe(ErrorCode::InvalidNetwork)
        ->and(paymentValidator()->validate(payloadFor(reqs(['scheme' => 'upto'])), reqs(), [])->code)
        ->toBe(ErrorCode::InvalidScheme);
});

it('rejects an underpaid authorization', function (): void {
    $payload = payloadFor(reqs(), ['value' => '9999']);

    expect(paymentValidator()->validate($payload, reqs(), [])->code)->toBe(ErrorCode::ValueMismatch);
});

it('accepts an overpaid authorization', function (): void {
    expect(paymentValidator()->validate(payloadFor(reqs(), ['value' => '10001']), reqs(), []))->toBeNull();
});

it('rejects an authorization paying the wrong wallet', function (): void {
    $payload = payloadFor(reqs(), ['to' => '0x0000000000000000000000000000000000000003']);

    expect(paymentValidator()->validate($payload, reqs(), [])->code)->toBe(ErrorCode::RecipientMismatch);
});

it('rejects an expired authorization', function (): void {
    $payload = payloadFor(reqs(), ['validBefore' => (string) (NOW - 10)]);

    expect(paymentValidator()->validate($payload, reqs(), [])->code)->toBe(ErrorCode::InvalidValidBefore);
});

it('rejects an authorization that is not valid yet', function (): void {
    $payload = payloadFor(reqs(), ['validAfter' => (string) (NOW + 120)]);

    expect(paymentValidator()->validate($payload, reqs(), [])->code)->toBe(ErrorCode::InvalidValidAfter);
});

it('tolerates small clock skew at the edges of the validity window', function (): void {
    $justExpired = payloadFor(reqs(), ['validBefore' => (string) (NOW - 2)]);
    $justStarted = payloadFor(reqs(), ['validAfter' => (string) (NOW + 2)]);

    expect(paymentValidator()->validate($justExpired, reqs(), []))->toBeNull()
        ->and(paymentValidator()->validate($justStarted, reqs(), []))->toBeNull();
});

it('leaves a scheme it cannot read locally to the facilitator', function (): void {
    $payload = new PaymentPayload(
        x402Version: Version::V2,
        accepted: reqs(),
        payload: ['opaqueProof' => 'deadbeef'],
    );

    expect(paymentValidator()->validate($payload, reqs(), ['https://api.test/report']))->toBeNull();
});

it('reads permit2-shaped payloads', function (): void {
    $payload = new PaymentPayload(
        x402Version: Version::V2,
        accepted: reqs(),
        payload: [
            'signature' => '0xsig',
            'permit2Authorization' => [
                'permitted' => ['token' => '0xtoken', 'amount' => '10000'],
                'from' => '0xpayer',
                'deadline' => (string) (NOW + 60),
                'witness' => ['to' => '0x209693Bc6afc0C5328bA36FaF03C514EF312287C', 'validAfter' => (string) (NOW - 60)],
            ],
        ],
    );

    expect($payload->authorizedValue())->toBe('10000')
        ->and($payload->recipientAddress())->toBe('0x209693Bc6afc0C5328bA36FaF03C514EF312287C')
        ->and($payload->validBefore())->toBe(NOW + 60)
        ->and(paymentValidator()->validate($payload, reqs(), []))->toBeNull();
});

it('fingerprints a payload stably and distinctly', function (): void {
    $requirements = reqs();
    $payload = payloadFor($requirements);
    $same = new PaymentPayload(2, $requirements, $payload->payload);
    $different = payloadFor($requirements);

    expect($payload->fingerprint())->toBe($same->fingerprint())
        ->and($payload->fingerprint())->not->toBe($different->fingerprint())
        ->and($payload->fingerprint())->toHaveLength(64);
});

it('fingerprints the same signature differently per network', function (): void {
    $payload = payloadFor(reqs());
    $elsewhere = new PaymentPayload(2, reqs(['network' => 'eip155:8453']), $payload->payload);

    expect($payload->fingerprint())->not->toBe($elsewhere->fingerprint());
});
