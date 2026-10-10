<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Requestway\X402\Enums\Mode;
use Requestway\X402\Enums\PaymentStatus;
use Requestway\X402\Models\X402Payment;

/**
 * The published migration has to run in a real application, under the name and
 * timestamp the publisher gives it — not just when the test suite includes the
 * stub directly.
 */
it('publishes a migration that runs and creates a usable table', function (): void {
    Schema::dropIfExists('x402_payments');

    $this->artisan('vendor:publish', ['--tag' => 'x402-migrations', '--force' => true])->assertSuccessful();

    $published = File::glob(database_path('migrations/*_create_x402_payments_table.php'));

    expect($published)->toHaveCount(1);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('x402_payments'))->toBeTrue()
        ->and(Schema::hasColumns('x402_payments', [
            'id', 'route', 'method', 'resource', 'amount_atomic', 'amount_usd', 'asset',
            'asset_decimals', 'network', 'payer', 'pay_to', 'transaction_hash', 'status',
            'failure_reason', 'facilitator', 'mode', 'request_id', 'meta',
            'created_at', 'updated_at',
        ]))->toBeTrue();

    $payment = X402Payment::query()->create([
        'route' => 'premium',
        'method' => 'GET',
        'resource' => 'https://api.test/premium',
        'amount_atomic' => '99999999999999999999999999999999',
        'amount_usd' => '0.01',
        'status' => PaymentStatus::Settled,
        'mode' => Mode::Enforce,
        'meta' => ['stage' => 'settle'],
    ]);

    expect($payment->fresh()->amount_atomic)->toBe('99999999999999999999999999999999')
        ->and($payment->fresh()->meta)->toBe(['stage' => 'settle']);

    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('x402_payments'))->toBeFalse();

    File::delete($published);
});

it('honours a custom table name when the migration runs', function (): void {
    Schema::dropIfExists('x402_payments');
    config()->set('x402.database.table', 'agent_payments');

    $this->artisan('vendor:publish', ['--tag' => 'x402-migrations', '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('agent_payments'))->toBeTrue()
        ->and((new X402Payment)->getTable())->toBe('agent_payments');

    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();

    File::delete(File::glob(database_path('migrations/*_create_x402_payments_table.php')));
});
