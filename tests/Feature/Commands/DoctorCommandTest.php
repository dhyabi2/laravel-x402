<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Messages\SupportedKind;
use HydraLabs\X402\Facades\X402;
use HydraLabs\X402\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

function supportedFake(array $kinds = [['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:84532']]): void
{
    Http::fake([
        TestCase::FACILITATOR.'/supported' => Http::response([
            'kinds' => $kinds,
            'extensions' => [],
            'signers' => [],
        ]),
    ]);
}

it('passes a healthy testnet setup', function (): void {
    supportedFake();

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(0)
        ->and($output)->toContain('Wallet', '0x209693Bc6afc0C5328bA36FaF03C514EF312287C')
        ->and($output)->toContain('Base Sepolia (testnet)', 'USDC on Base Sepolia (6 decimals)')
        ->and($output)->toContain('1 paid route(s)', 'exact on Base Sepolia');
});

it('warns that a mainnet moves real money', function (): void {
    config()->set('x402.network', 'base');
    supportedFake([['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:8453']]);

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(0)->and($output)->toContain('mainnet: payments are real', 'WARN');
});

it('fails when no wallet is configured', function (): void {
    config()->set('x402.pay_to', null);
    supportedFake();

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(1)->and($output)->toContain('No x402 wallet configured');
});

it('fails when the wallet is not an EVM address', function (): void {
    config()->set('x402.pay_to', '0xnope');
    supportedFake();

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(1)->and($output)->toContain('EVM address');
});

it('fails when a configured network has no asset', function (): void {
    config()->set('x402.additional_networks', ['eip155:43113']);
    supportedFake();

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(1)->and($output)->toContain('No asset configured for Avalanche Fuji');
});

it('fails when the configured network is not a network at all', function (): void {
    config()->set('x402.network', 'not-a-network');
    supportedFake();

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(1)->and($output)->toContain('Networks');
});

it('warns when the replay cache is not shared between servers', function (): void {
    supportedFake();

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(0)
        ->and($output)->toContain('not shared between servers', 'redis, memcached, dynamodb or database');
});

it('reports a usable shared replay cache without warning about it', function (): void {
    config()->set('cache.stores.shared', ['driver' => 'array']);
    config()->set('x402.replay.store', 'shared');
    supportedFake();

    [, $output] = runCommand('x402:doctor');

    expect($output)->toContain('shared, 86400s retention')
        ->and($output)->not->toContain('not shared between servers');
});

it('fails when the replay cache store does not exist', function (): void {
    config()->set('x402.replay.store', 'nonexistent');
    supportedFake();

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(1)->and($output)->toContain('is unusable');
});

it('fails when the payments table is missing', function (): void {
    Schema::drop('x402_payments');
    supportedFake();

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(1)
        ->and($output)->toContain('is missing', 'vendor:publish --tag=x402-migrations');
});

it('warns when recording is switched off', function (): void {
    config()->set('x402.database.enabled', false);
    supportedFake();

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(0)->and($output)->toContain('payments are not stored');
});

it('counts the payments already recorded', function (): void {
    X402::fake();
    supportedFake();

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements));

    [, $output] = runCommand('x402:doctor');

    expect($output)->toContain('1 payment(s) recorded');
});

it('fails when the facilitator cannot be reached', function (): void {
    Http::fake(fn (): never => throw new Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect'));

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(1)->and($output)->toContain('Could not reach the facilitator');
});

it('fails when the facilitator does not support the configured network', function (): void {
    supportedFake([['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:8453']]);

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(1)
        ->and($output)->toContain('does not support exact on Base Sepolia', 'exact/eip155:8453');
});

it('says so when a facilitator supports nothing at all', function (): void {
    supportedFake([]);

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(1)->and($output)->toContain('It supports: nothing');
});

it('accepts a namespace wildcard from the facilitator', function (): void {
    supportedFake([['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:*']]);

    [$status] = runCommand('x402:doctor');

    expect($status)->toBe(0);
});

it('can skip the facilitator call entirely', function (): void {
    Http::fake();

    [$status, $output] = runCommand('x402:doctor', ['--skip-facilitator' => true]);

    expect($status)->toBe(0)->and($output)->not->toContain('Facilitator');

    Http::assertNothingSent();
});

it('warns when there are no paid routes yet', function (): void {
    supportedFake();

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(0)->and($output)->toContain('No paid routes found');
});

it('fails when a route is misconfigured', function (): void {
    supportedFake();

    Route::get('/broken', fn (): array => [])->middleware('x402:0.01,network=eip155:43113');

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(1)->and($output)->toContain('GET /broken');
});

it('warns when the package is switched off or only observing', function (string $mode, string $expected): void {
    config()->set('x402.mode', $mode);
    supportedFake();

    [$status, $output] = runCommand('x402:doctor');

    expect($status)->toBe(0)->and($output)->toContain($expected);
})->with([
    ['observe', 'nothing is charged'],
    ['off', 'not protected'],
]);

it('reports how many kinds a facilitator supports', function (): void {
    supportedFake([
        ['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:84532'],
        ['x402Version' => 2, 'scheme' => 'upto', 'network' => 'eip155:84532'],
    ]);

    [, $output] = runCommand('x402:doctor');

    expect($output)->toContain('2 kind(s) supported');
});

it('builds the supported kinds it reports from the facilitator response', function (): void {
    $kind = new SupportedKind(2, 'exact', 'eip155:84532');

    expect($kind->matches('exact', 'eip155:84532', 2))->toBeTrue();
});
