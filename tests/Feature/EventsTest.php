<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Errors\ErrorCode;
use HydraLabs\X402\Enums\Mode;
use HydraLabs\X402\Events\PaymentFailed;
use HydraLabs\X402\Events\PaymentRequired;
use HydraLabs\X402\Events\PaymentSettled;
use HydraLabs\X402\Events\PaymentVerified;
use HydraLabs\X402\Facades\X402;
use HydraLabs\X402\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->fake = X402::fake();

    Route::get('/premium', fn (): array => ['data' => 'secret'])
        ->middleware('x402:0.01,description=Market data')
        ->name('premium');
});

it('fires PaymentRequired when a client has not paid', function (): void {
    Event::fake([PaymentRequired::class]);

    $this->getJson('/premium?symbol=ETH', ['X-Request-Id' => 'req-123']);

    Event::assertDispatched(PaymentRequired::class, function (PaymentRequired $event): bool {
        return $event->context->route->routeName === 'premium'
            && $event->context->method === 'GET'
            && $event->context->resourceUrl === 'http://localhost/premium?symbol=ETH'
            && $event->context->mode === Mode::Enforce
            && $event->context->amountUsd() === '0.01'
            && $event->context->amountAtomic() === '10000'
            && $event->context->requestId === 'req-123'
            && $event->context->payer() === null;
    });
});

it('fires PaymentVerified then PaymentSettled on a successful payment', function (): void {
    $requirements = paymentRequired($this->getJson('/premium'))->first();

    Event::fake([PaymentVerified::class, PaymentSettled::class, PaymentFailed::class]);

    $this->getJson('/premium', payment($requirements));

    Event::assertDispatched(PaymentVerified::class);
    Event::assertNotDispatched(PaymentFailed::class);
    Event::assertDispatched(PaymentSettled::class, function (PaymentSettled $event): bool {
        return $event->settlement->success
            && $event->transaction() !== ''
            && $event->context->payer() === TestCase::PAYER
            && $event->context->asset?->symbol === 'USDC'
            && $event->context->network() === 'eip155:84532';
    });
});

it('fires PaymentFailed with the stage and reason when verification is rejected', function (): void {
    $this->fake->rejectVerification(ErrorCode::InsufficientFunds);
    $requirements = paymentRequired($this->getJson('/premium'))->first();

    Event::fake([PaymentFailed::class, PaymentSettled::class]);

    $this->getJson('/premium', payment($requirements));

    Event::assertNotDispatched(PaymentSettled::class);
    Event::assertDispatched(PaymentFailed::class, function (PaymentFailed $event): bool {
        return $event->stage === 'verify'
            && $event->reason === 'insufficient_funds'
            && $event->servedWithoutPayment() === false
            && $event->verification === null;
    });
});

it('flags a deferred settlement failure as served without payment', function (): void {
    config()->set('x402.settlement.mode', 'after_response');
    $this->fake->rejectSettlement(ErrorCode::InsufficientFunds);
    $requirements = paymentRequired($this->getJson('/premium'))->first();

    Event::fake([PaymentFailed::class]);

    $this->getJson('/premium', payment($requirements))->assertOk();

    Event::assertDispatched(PaymentFailed::class, function (PaymentFailed $event): bool {
        return $event->stage === 'settle_after_response'
            && $event->servedWithoutPayment() === true
            && $event->settlement?->errorReason === 'insufficient_funds';
    });
});

it('carries the exception when the facilitator itself failed', function (): void {
    Illuminate\Support\Facades\Http::fake([
        TestCase::FACILITATOR.'/verify' => Illuminate\Support\Facades\Http::response([], 500),
    ]);

    X402::useFacilitator(app()->make(HydraLabs\X402\Facilitators\HttpFacilitator::class, [
        'baseUrl' => TestCase::FACILITATOR,
    ]));

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    Event::fake([PaymentFailed::class]);

    $this->getJson('/premium', payment($requirements));

    Event::assertDispatched(PaymentFailed::class, function (PaymentFailed $event): bool {
        return $event->reason === 'facilitator_unavailable' && $event->exception !== null;
    });
});

it('exposes a loggable context that never contains a full payer address by default', function (): void {
    $requirements = paymentRequired($this->getJson('/premium'))->first();

    Event::fake([PaymentSettled::class]);

    $this->getJson('/premium', payment($requirements));

    Event::assertDispatched(PaymentSettled::class, function (PaymentSettled $event): bool {
        return $event->context->payerForLog(false) === '0x857b…6b66'
            && $event->context->payerForLog(true) === TestCase::PAYER
            && $event->context->toArray()['price_usd'] === '0.01'
            && $event->context->toArray()['asset'] === 'USDC';
    });
});

it('fires no payment events for a bypassed request', function (): void {
    config()->set('x402.bypass.ips', ['127.0.0.1']);

    Event::fake([PaymentRequired::class, PaymentVerified::class, PaymentSettled::class, PaymentFailed::class]);

    $this->getJson('/premium')->assertOk();

    Event::assertNothingDispatched();
});
