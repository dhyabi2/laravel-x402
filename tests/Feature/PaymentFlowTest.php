<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Errors\ErrorCode;
use HydraLabs\X402\Core\Protocol\V1Codec;
use HydraLabs\X402\Core\Protocol\V2Codec;
use HydraLabs\X402\Enums\PaymentStatus;
use HydraLabs\X402\Facades\X402;
use HydraLabs\X402\Http\Middleware\RequirePayment;
use HydraLabs\X402\Models\X402Payment;
use HydraLabs\X402\Tests\TestCase;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->fake = X402::fake();

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');
    Route::post('/premium', fn (): array => ['created' => true])->middleware('x402:0.05');
    Route::get('/broken', fn () => response()->json(['error' => 'nope'], 500))->middleware('x402:0.01');
});

it('serves the resource and attaches the settlement receipt when payment is good', function (): void {
    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $response = $this->getJson('/premium', payment($requirements));

    $response->assertOk()->assertExactJson(['data' => 'secret']);

    $settlement = settlement($response);

    expect($settlement->success)->toBeTrue()
        ->and($settlement->network)->toBe('eip155:84532')
        ->and($settlement->transaction)->toStartWith('0x')
        ->and($settlement->payer)->toBe(TestCase::PAYER)
        ->and($settlement->amount)->toBe('10000');

    X402::assertVerified(1);
    X402::assertSettled(1);
    X402::assertSettledFor('/premium', '0.01');
});

it('marks a paid response private so a shared cache cannot serve it to someone else', function (): void {
    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $response = $this->getJson('/premium', payment($requirements));

    expect($response->headers->get('Cache-Control'))->toContain('private');
});

it('verifies before the route runs and settles after it succeeds', function (): void {
    $order = [];

    Route::get('/ordered', function () use (&$order): array {
        $order[] = 'route';

        return [];
    })->middleware('x402:0.01');

    $this->fake->rejectVerification(function () use (&$order) {
        $order[] = 'verify';

        return HydraLabs\X402\Core\Messages\VerifyResponse::valid(TestCase::PAYER);
    });

    $requirements = paymentRequired($this->getJson('/ordered'))->first();
    $this->getJson('/ordered', payment($requirements));

    expect($order)->toBe(['verify', 'route']);
});

it('does not charge for a route that failed', function (): void {
    $requirements = paymentRequired($this->getJson('/broken'))->first();

    $response = $this->getJson('/broken', payment($requirements));

    $response->assertStatus(500);

    X402::assertVerified(1);
    X402::assertNotCharged();

    expect($response->headers->has(V2Codec::PAYMENT_RESPONSE))->toBeFalse();
});

it('lets a client retry the same payment after the route failed, since nothing was charged', function (): void {
    $requirements = paymentRequired($this->getJson('/broken'))->first();
    $headers = payment($requirements);

    $this->getJson('/broken', $headers)->assertStatus(500);

    // Same payload, now against a working route: the replay claim was released.
    Route::get('/broken-fixed', fn (): array => ['ok' => true])->middleware('x402:0.01');

    expect($this->getJson('/broken', $headers)->status())->toBe(500);

    X402::assertNotCharged();
});

it('refuses to serve the resource when settlement fails', function (): void {
    $this->fake->rejectSettlement(ErrorCode::InsufficientFunds);

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $response = $this->getJson('/premium', payment($requirements));

    $response->assertStatus(402)->assertJsonMissingPath('data');

    $settlement = settlement($response);

    expect($settlement->success)->toBeFalse()
        ->and($settlement->errorReason)->toBe('insufficient_funds')
        ->and($response->json('error'))->toBe(ErrorCode::InsufficientFunds->message());
});

it('records a failed settlement', function (): void {
    $this->fake->rejectSettlement(ErrorCode::InvalidTransactionState);

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements));

    $payment = X402Payment::query()->failed()->sole();

    expect($payment->failure_reason)->toBe('invalid_transaction_state')
        ->and($payment->route)->toBe('GET /premium')
        ->and($payment->amount_atomic)->toBe('10000')
        ->and($payment->payer)->toBe(TestCase::PAYER);
});

it('retries exactly once when settlement comes back pending, as the spec allows', function (): void {
    $this->fake->pendingSettlement('0xbroadcast');

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $response = $this->getJson('/premium', payment($requirements));

    // The spec marks settlement_pending non-terminal: the transaction may yet
    // confirm, so the resource is served and the payment recorded as pending.
    $response->assertOk();

    expect($this->fake->settleCount())->toBe(2)
        ->and(settlement($response)->transaction)->toBe('0xbroadcast')
        ->and(X402Payment::query()->sole()->status)->toBe(PaymentStatus::Pending);
});

it('settles after the response in after_response mode', function (): void {
    config()->set('x402.settlement.mode', 'after_response');

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $response = $this->getJson('/premium', payment($requirements));

    $response->assertOk()
        ->assertExactJson(['data' => 'secret'])
        ->assertHeader(RequirePayment::DEFERRED_HEADER, 'deferred');

    expect($response->headers->has(V2Codec::PAYMENT_RESPONSE))->toBeFalse();

    X402::assertSettled(1);

    expect(X402Payment::query()->sole()->status)->toBe(PaymentStatus::Settled);
});

it('serves the resource but records the failure when a deferred settlement fails', function (): void {
    config()->set('x402.settlement.mode', 'after_response');
    $this->fake->rejectSettlement(ErrorCode::InsufficientFunds);

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $this->getJson('/premium', payment($requirements))->assertOk();

    $payment = X402Payment::query()->sole();

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('insufficient_funds')
        ->and($payment->meta['stage'])->toBe('settle_after_response');
});

it('records a verified payment up front in after_response mode, in case the process dies', function (): void {
    config()->set('x402.settlement.mode', 'after_response');

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements));

    // One row, created at verification and updated at settlement.
    expect(X402Payment::query()->count())->toBe(1)
        ->and(X402Payment::query()->sole()->status)->toBe(PaymentStatus::Settled);
});

it('accepts a per-route settlement mode', function (): void {
    Route::get('/fast', fn (): array => ['ok' => true])->middleware('x402:0.01,settle=after_response');

    $requirements = paymentRequired($this->getJson('/fast'))->first();

    $this->getJson('/fast', payment($requirements))
        ->assertOk()
        ->assertHeader(RequirePayment::DEFERRED_HEADER, 'deferred');
});

it('prices each HTTP method on a shared URI separately', function (): void {
    $get = paymentRequired($this->getJson('/premium'))->first();
    $post = paymentRequired($this->postJson('/premium'))->first();

    expect($get->amount)->toBe('10000')->and($post->amount)->toBe('50000');

    $this->postJson('/premium', [], payment($post))->assertOk();

    X402::assertSettledFor('/premium', '0.05');
});

it('accepts a legacy v1 payment on the X-PAYMENT header', function (): void {
    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $response = $this->getJson('/premium', [
        V1Codec::PAYMENT_SIGNATURE => paymentHeader($requirements, version: 1),
    ]);

    $response->assertOk()->assertExactJson(['data' => 'secret']);

    // A v1 client is answered on the v1 response header.
    expect($response->headers->get(V1Codec::PAYMENT_RESPONSE))->toBeString()->not->toBeEmpty()
        ->and($response->headers->has(V2Codec::PAYMENT_RESPONSE))->toBeFalse();

    X402::assertSettled(1);
});

it('sends a v1 payload to the facilitator in the v1 wire shape', function (): void {
    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $this->getJson('/premium', [
        V1Codec::PAYMENT_SIGNATURE => paymentHeader($requirements, version: 1),
    ]);

    $settled = $this->fake->settlements()[0];

    expect($settled['payload']->x402Version)->toBe(1)
        ->and($settled['payload']->toArray())->toHaveKeys(['x402Version', 'scheme', 'network', 'payload'])
        ->and($settled['payload']->toArray())->not->toHaveKey('accepted');
});

it('prefers the v2 header when a client sends both', function (): void {
    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $this->getJson('/premium', [
        V2Codec::PAYMENT_SIGNATURE => paymentHeader($requirements),
        V1Codec::PAYMENT_SIGNATURE => paymentHeader($requirements, version: 1),
    ])->assertOk();

    expect($this->fake->settlements()[0]['payload']->x402Version)->toBe(2);
});

it('passes the resource metadata to the facilitator', function (): void {
    Route::get('/described', fn (): array => [])
        ->middleware('x402:0.01,description=Market snapshot');

    $requirements = paymentRequired($this->getJson('/described'))->first();
    $this->getJson('/described', payment($requirements));

    $resource = $this->fake->settlements()[0]['resource'];

    expect($resource->url)->toBe('http://localhost/described')
        ->and($resource->description)->toBe('Market snapshot');
});

it('does nothing at all when the package is switched off', function (): void {
    config()->set('x402.mode', 'off');

    $this->getJson('/premium')->assertOk()->assertExactJson(['data' => 'secret']);

    X402::assertNothingVerified();
});
