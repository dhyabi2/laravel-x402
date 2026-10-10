<?php

declare(strict_types=1);

use HydraLabs\X402\Enums\Mode;
use HydraLabs\X402\Enums\PaymentStatus;
use HydraLabs\X402\Facades\X402;
use HydraLabs\X402\Models\X402Payment;
use HydraLabs\X402\Tests\TestCase;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    X402::fake();

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01')->name('premium');
});

it('records a settled payment with everything needed to reconcile it', function (): void {
    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $response = $this->getJson('/premium?symbol=ETH', payment($requirements));

    $payment = X402Payment::query()->settled()->sole();

    expect($payment->route)->toBe('premium')
        ->and($payment->method)->toBe('GET')
        ->and($payment->resource)->toBe('http://localhost/premium?symbol=ETH')
        ->and($payment->amount_atomic)->toBe('10000')
        ->and($payment->amount_usd)->toBe('0.01')
        ->and($payment->asset)->toBe('0x036CbD53842c5426634e7929541eC2318f3dCF7e')
        ->and($payment->asset_decimals)->toBe(6)
        ->and($payment->network)->toBe('eip155:84532')
        ->and($payment->payer)->toBe(TestCase::PAYER)
        ->and($payment->pay_to)->toBe(TestCase::PAY_TO)
        ->and($payment->transaction_hash)->toBe(settlement($response)->transaction)
        ->and($payment->status)->toBe(PaymentStatus::Settled)
        ->and($payment->mode)->toBe(Mode::Enforce)
        ->and($payment->facilitator)->toBe('facilitator.test')
        ->and($payment->formattedAmount())->toBe('$0.01');
});

it('stores an atomic amount larger than a 64-bit integer', function (): void {
    config()->set('x402.assets', ['eip155:84532' => ['decimals' => 30]]);

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements));

    expect(X402Payment::query()->settled()->sole()->amount_atomic)
        ->toBe('10000000000000000000000000000');
});

it('can be switched off', function (): void {
    config()->set('x402.database.enabled', false);

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements))->assertOk();

    expect(X402Payment::query()->count())->toBe(0);
});

it('serves the resource even if recording fails', function (): void {
    config()->set('x402.database.table', 'table_that_does_not_exist');

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $this->getJson('/premium', payment($requirements))->assertOk();

    X402::assertSettled(1);
});

it('honours a configured table name and connection', function (): void {
    config()->set('x402.database.table', 'x402_payments');
    config()->set('x402.database.connection', 'testing');

    expect((new X402Payment)->getTable())->toBe('x402_payments')
        ->and((new X402Payment)->getConnectionName())->toBe('testing');

    config()->set('x402.database.connection', null);

    expect((new X402Payment)->getConnectionName())->toBeNull();
});

it('filters payments by status', function (): void {
    X402Payment::query()->create(['method' => 'GET', 'resource' => 'a', 'status' => PaymentStatus::Settled, 'mode' => Mode::Enforce]);
    X402Payment::query()->create(['method' => 'GET', 'resource' => 'b', 'status' => PaymentStatus::Failed, 'mode' => Mode::Enforce]);
    X402Payment::query()->create(['method' => 'GET', 'resource' => 'c', 'status' => PaymentStatus::Observed, 'mode' => Mode::Observe]);

    expect(X402Payment::query()->settled()->count())->toBe(1)
        ->and(X402Payment::query()->failed()->count())->toBe(1)
        ->and(X402Payment::query()->observed()->count())->toBe(1);
});

it('filters payments by route, payer and date range', function (): void {
    X402Payment::query()->create([
        'route' => 'premium', 'method' => 'GET', 'resource' => 'a',
        'status' => PaymentStatus::Settled, 'mode' => Mode::Enforce,
        'payer' => TestCase::PAYER, 'created_at' => '2026-01-15 10:00:00',
    ]);

    X402Payment::query()->create([
        'route' => 'reports', 'method' => 'GET', 'resource' => 'b',
        'status' => PaymentStatus::Settled, 'mode' => Mode::Enforce,
        'payer' => '0xother', 'created_at' => '2026-03-01 10:00:00',
    ]);

    expect(X402Payment::query()->forRoute('premium')->count())->toBe(1)
        ->and(X402Payment::query()->forPayer(strtolower(TestCase::PAYER))->count())->toBe(1)
        ->and(X402Payment::query()->forPayer(strtoupper(TestCase::PAYER))->count())->toBe(1)
        ->and(X402Payment::query()->between('2026-01-01', '2026-02-01')->count())->toBe(1)
        ->and(X402Payment::query()->settled()->between('2026-01-01', '2026-12-31')->count())->toBe(2);
});

it('reports revenue over a period by combining scopes', function (): void {
    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements));

    $total = X402Payment::query()
        ->settled()
        ->forRoute('premium')
        ->between(now()->subDay(), now()->addDay())
        ->get()
        ->sum(fn (X402Payment $payment): int => (int) $payment->amount_atomic);

    expect($total)->toBe(10000);
});

it('falls back to the USD amount when no atomic amount was recorded', function (): void {
    $payment = new X402Payment(['amount_usd' => '0.01']);

    expect($payment->formattedAmount())->toBe('$0.01')
        ->and((new X402Payment)->formattedAmount())->toBe('-');
});
