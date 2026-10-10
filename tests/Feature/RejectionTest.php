<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Errors\ErrorCode;
use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Core\Messages\VerifyResponse;
use HydraLabs\X402\Core\Protocol\V1Codec;
use HydraLabs\X402\Core\Protocol\V2Codec;
use HydraLabs\X402\Core\Support\Base64;
use HydraLabs\X402\Core\Support\Json;
use HydraLabs\X402\Facades\X402;
use HydraLabs\X402\Models\X402Payment;
use HydraLabs\X402\Tests\TestCase;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->fake = X402::fake();

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');
    Route::get('/cheap', fn (): array => ['data' => 'cheap'])->middleware('x402:0.001');
});

function requirementsFor(string $uri = '/premium'): PaymentRequirements
{
    return paymentRequired(test()->getJson($uri))->first();
}

it('answers a malformed payment header with 400, never a 500', function (string $header): void {
    $response = $this->getJson('/premium', [V2Codec::PAYMENT_SIGNATURE => $header]);

    $response->assertStatus(400);

    expect($response->headers->get(V2Codec::PAYMENT_REQUIRED))->not->toBeEmpty();

    X402::assertNothingVerified();
})->with([
    'not base64' => ['this is not base64 !!'],
    'base64 of nonsense' => ['bm90IGpzb24='],
    'base64 of a JSON array' => ['WzEsMiwzXQ=='],
    'truncated base64' => ['eyJ4NDAyVmVyc2lvbiI6Mi'],
]);

it('answers a structurally invalid payload with 400', function (array $payload): void {
    $this->getJson('/premium', [
        V2Codec::PAYMENT_SIGNATURE => Base64::encode(Json::encode($payload)),
    ])->assertStatus(400);
})->with([
    'missing payload' => [['x402Version' => 2, 'accepted' => ['scheme' => 'exact']]],
    'missing accepted' => [['x402Version' => 2, 'payload' => ['signature' => '0x1']]],
    'unknown version' => [['x402Version' => 7, 'accepted' => [], 'payload' => ['a' => 'b']]],
]);

it('rejects an underpaid payment with the spec error code', function (): void {
    $requirements = requirementsFor();

    $response = $this->getJson('/premium', payment($requirements, amount: '9999'));

    $response->assertStatus(402);

    expect($response->json('error'))->toBe(ErrorCode::ValueMismatch->message());

    X402::assertNotCharged();
});

it('rejects a payment whose accepted echo lowers the price', function (): void {
    $requirements = requirementsFor();
    $tampered = new PaymentRequirements(
        scheme: $requirements->scheme,
        network: $requirements->network,
        amount: '1',
        asset: $requirements->asset,
        payTo: $requirements->payTo,
        maxTimeoutSeconds: $requirements->maxTimeoutSeconds,
        extra: $requirements->extra,
    );

    $this->getJson('/premium', payment($tampered))->assertStatus(402);

    X402::assertNothingVerified();
});

it('rejects a payment redirected to another wallet', function (): void {
    $requirements = requirementsFor();

    $response = $this->getJson('/premium', payment($requirements, overrides: [
        'to' => '0x0000000000000000000000000000000000000bad',
    ]));

    $response->assertStatus(402);

    expect($response->json('error'))->toBe(ErrorCode::RecipientMismatch->message());
});

it('rejects a payment for another network', function (): void {
    $elsewhere = new PaymentRequirements(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        payTo: TestCase::PAY_TO,
        maxTimeoutSeconds: 60,
        extra: ['name' => 'USD Coin', 'version' => '2'],
    );

    $response = $this->getJson('/premium', payment($elsewhere));

    $response->assertStatus(402);

    expect($response->json('error'))->toContain('eip155:8453');

    X402::assertNothingVerified();
});

it('rejects a payment bought for a different resource', function (): void {
    $cheap = requirementsFor('/cheap');
    $premium = requirementsFor('/premium');

    // A payload signed for /cheap, presented at /premium: same wallet, same
    // asset, but the resource echo gives it away even before the amount does.
    $response = $this->getJson('/premium', payment(
        $premium,
        new HydraLabs\X402\Core\Messages\ResourceInfo('http://localhost/cheap'),
    ));

    $response->assertStatus(402);

    expect($response->json('error'))->toContain('different resource');

    unset($cheap);
});

it('rejects an expired authorization', function (): void {
    $requirements = requirementsFor();

    $response = $this->getJson('/premium', payment($requirements, overrides: [
        'validBefore' => (string) (time() - 3600),
    ]));

    $response->assertStatus(402);

    expect($response->json('error'))->toBe(ErrorCode::InvalidValidBefore->message());
});

it('rejects an authorization that is not valid yet', function (): void {
    $requirements = requirementsFor();

    $response = $this->getJson('/premium', payment($requirements, overrides: [
        'validAfter' => (string) (time() + 3600),
    ]));

    $response->assertStatus(402);

    expect($response->json('error'))->toBe(ErrorCode::InvalidValidAfter->message());
});

it('rejects a replayed payment', function (): void {
    $requirements = requirementsFor();
    $headers = payment($requirements);

    $this->getJson('/premium', $headers)->assertOk();

    $replayed = $this->getJson('/premium', $headers);

    $replayed->assertStatus(402);

    expect($replayed->json('error'))->toContain('already been used');

    X402::assertSettled(1);
});

it('claims the payload before verification, so a replay cannot even reach the facilitator', function (): void {
    $requirements = requirementsFor();
    $headers = payment($requirements);

    $this->getJson('/premium', $headers);
    $this->getJson('/premium', $headers);

    X402::assertVerified(1);
});

it('releases the claim when verification fails, so a client can sign again', function (): void {
    $this->fake->rejectVerification(ErrorCode::InsufficientFunds);

    $requirements = requirementsFor();
    $headers = payment($requirements);

    $this->getJson('/premium', $headers)->assertStatus(402);

    // Funds arrive; the same payload is presented again and is now accepted.
    $this->fake->reset();

    $this->getJson('/premium', $headers)->assertOk();

    X402::assertSettled(1);
});

it('releases the claim when settlement fails', function (): void {
    $this->fake->rejectSettlement(ErrorCode::UnexpectedSettleError);

    $requirements = requirementsFor();
    $headers = payment($requirements);

    $this->getJson('/premium', $headers)->assertStatus(402);

    $this->fake->reset();

    $this->getJson('/premium', $headers)->assertOk();
});

it('reports a facilitator rejection using its own reason', function (): void {
    $this->fake->rejectVerification(ErrorCode::InvalidSignature);

    $requirements = requirementsFor();

    $response = $this->getJson('/premium', payment($requirements));

    $response->assertStatus(402);

    expect($response->json('error'))->toBe(ErrorCode::InvalidSignature->message())
        ->and(X402Payment::query()->failed()->sole()->failure_reason)->toBe('invalid_exact_evm_payload_signature');
});

it('passes through a facilitator reason it has never heard of', function (): void {
    $this->fake->rejectVerification(fn (): VerifyResponse => VerifyResponse::invalid('kyt_screening_failed'));

    $response = $this->getJson('/premium', payment(requirementsFor()));

    $response->assertStatus(402);

    expect($response->json('error'))->toContain('kyt_screening_failed');
});

it('never puts a signature in the response', function (): void {
    $requirements = requirementsFor();
    $headers = payment($requirements);
    $signature = Json::decodeObject(Base64::decode($headers[V2Codec::PAYMENT_SIGNATURE]))['payload']['signature'];

    $response = $this->getJson('/premium', $headers);

    expect($response->content())->not->toContain($signature)
        ->and(json_encode($response->headers->all()))->not->toContain($signature);
});

it('rejects a legacy payload for a scheme the route does not accept', function (): void {
    $elsewhere = new PaymentRequirements(
        scheme: 'upto',
        network: 'eip155:84532',
        amount: '10000',
        asset: '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
        payTo: TestCase::PAY_TO,
        maxTimeoutSeconds: 60,
    );

    $this->getJson('/premium', [
        V1Codec::PAYMENT_SIGNATURE => paymentHeader($elsewhere, version: 1),
    ])->assertStatus(402);
});

it('rejects a legacy payload that underpays, even without an accepted echo', function (): void {
    $requirements = requirementsFor();

    $response = $this->getJson('/premium', [
        V1Codec::PAYMENT_SIGNATURE => paymentHeader($requirements, amount: '1', version: 1),
    ]);

    $response->assertStatus(402);

    expect($response->json('error'))->toBe(ErrorCode::ValueMismatch->message());
});
