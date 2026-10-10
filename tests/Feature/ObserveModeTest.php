<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Requestway\X402\Enums\Mode;
use Requestway\X402\Enums\PaymentStatus;
use Requestway\X402\Events\PaymentObserved;
use Requestway\X402\Facades\X402;
use Requestway\X402\Http\Middleware\RequirePayment;
use Requestway\X402\Models\X402Payment;

beforeEach(function (): void {
    X402::fake();
    config()->set('x402.mode', 'observe');

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.25');
});

it('serves the resource and charges nothing', function (): void {
    $response = $this->getJson('/premium');

    $response->assertOk()
        ->assertExactJson(['data' => 'secret'])
        ->assertHeader(RequirePayment::WOULD_CHARGE_HEADER, '$0.25');

    X402::assertNothingVerified();
    X402::assertNotCharged();
});

it('ignores a payment header rather than charging for it', function (): void {
    config()->set('x402.mode', 'enforce');
    $requirements = paymentRequired($this->getJson('/premium'))->first();

    config()->set('x402.mode', 'observe');

    $this->getJson('/premium', payment($requirements))
        ->assertOk()
        ->assertHeader(RequirePayment::WOULD_CHARGE_HEADER, '$0.25');

    X402::assertNotCharged();
});

it('fires PaymentObserved with what would have been charged', function (): void {
    Event::fake([PaymentObserved::class]);

    $this->getJson('/premium?symbol=ETH');

    Event::assertDispatched(PaymentObserved::class, function (PaymentObserved $event): bool {
        return $event->context->mode === Mode::Observe
            && $event->context->amountUsd() === '0.25'
            && $event->context->amountAtomic() === '250000'
            && $event->context->resourceUrl === 'http://localhost/premium?symbol=ETH'
            && $event->context->asset?->symbol === 'USDC';
    });
});

it('records what would have been charged', function (): void {
    $this->getJson('/premium');

    $payment = X402Payment::query()->observed()->sole();

    expect($payment->status)->toBe(PaymentStatus::Observed)
        ->and($payment->mode)->toBe(Mode::Observe)
        ->and($payment->amount_atomic)->toBe('250000')
        ->and($payment->amount_usd)->toBe('0.25')
        ->and($payment->payer)->toBeNull()
        ->and($payment->formattedAmount())->toBe('$0.25');
});

it('can observe without writing to the database', function (): void {
    config()->set('x402.database.record_observed', false);

    $this->getJson('/premium')->assertOk();

    expect(X402Payment::query()->count())->toBe(0);
});

it('still honours bypass rules in observe mode', function (): void {
    config()->set('x402.bypass.ips', ['127.0.0.1']);

    $this->getJson('/premium')
        ->assertOk()
        ->assertHeader(RequirePayment::BYPASS_HEADER, 'ip-allowlist');

    expect(X402Payment::query()->count())->toBe(0);
});
