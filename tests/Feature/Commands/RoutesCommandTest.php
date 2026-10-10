<?php

declare(strict_types=1);

use HydraLabs\X402\Tests\Support\TestController;
use Illuminate\Support\Facades\Route;

it('lists every paid route with its price, networks and bypass rules', function (): void {
    config()->set('x402.bypass.ips', ['10.0.0.1']);

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01,description=Market data');
    Route::get('/members', fn (): array => [])->middleware('x402:0.25,free-for-auth');
    Route::get('/unprotected', fn (): array => []);

    [$status, $output] = runCommand('x402:routes');

    expect($status)->toBe(0)
        ->and($output)->toContain('/premium', '$0.01', 'Base Sepolia', 'Market data')
        ->and($output)->toContain('/members', '$0.25', 'auth', 'ips(1)')
        ->and($output)->not->toContain('/unprotected');
});

it('shows the mode, settlement mode and wallet', function (): void {
    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    [, $output] = runCommand('x402:routes');

    expect($output)->toContain('enforce', 'before_response', '0x209693Bc6afc0C5328bA36FaF03C514EF312287C');
});

it('warns loudly in observe mode', function (): void {
    config()->set('x402.mode', 'observe');

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    [, $output] = runCommand('x402:routes');

    expect($output)->toContain('nothing is charged');
});

it('says so when no wallet is configured', function (): void {
    config()->set('x402.pay_to', null);

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    [$status, $output] = runCommand('x402:routes');

    expect($status)->toBe(1)->and($output)->toContain('not configured');
});

it('explains that there is nothing to list', function (): void {
    [$status, $output] = runCommand('x402:routes');

    expect($status)->toBe(0)
        ->and($output)->toContain('No paid routes found', "middleware('x402:0.01')");
});

it('fails and names the route when one is misconfigured', function (): void {
    Route::get('/broken', fn (): array => [])->middleware('x402:0.01,network=eip155:43113');

    [$status, $output] = runCommand('x402:routes');

    expect($status)->toBe(1)
        ->and($output)->toContain('/broken', 'no asset is configured', 'error');
});

it('includes attribute and macro protected routes', function (): void {
    Route::get('/attr', [TestController::class, 'show'])->middleware('x402');
    Route::get('/macro', fn (): array => [])->paid('0.07');

    [, $output] = runCommand('x402:routes');

    expect($output)->toContain('/attr', '$0.02', '/macro', '$0.07');
});

it('can emit the catalogue as JSON', function (): void {
    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    [$status, $output] = runCommand('x402:routes', ['--json' => true]);

    $document = json_decode($output, true);

    expect($status)->toBe(0)
        ->and($document)->toHaveKeys(['x402Version', 'items', 'pagination'])
        ->and($document['items'][0]['accepts'][0]['amount'])->toBe('10000');
});

it('truncates a very long description', function (): void {
    Route::get('/verbose', fn (): array => [])
        ->middleware('x402:0.01,description='.str_repeat('a', 100));

    [, $output] = runCommand('x402:routes');

    expect($output)->toContain('…')->and($output)->not->toContain(str_repeat('a', 100));
});

it('lists multiple networks for one route', function (): void {
    config()->set('x402.additional_networks', ['base']);

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    [, $output] = runCommand('x402:routes');

    expect($output)->toContain('Base Sepolia, Base');
});

it('lists the configured price tiers so their names are discoverable', function (): void {
    Route::get('/premium', fn (): array => [])->middleware('x402:premium');

    [, $output] = runCommand('x402:routes');

    expect($output)->toContain('micro=$0.001', 'standard=$0.01', 'premium=$0.1');
});

it('omits the tiers line when none are configured', function (): void {
    config()->set('x402.tiers', []);

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    [, $output] = runCommand('x402:routes');

    expect($output)->not->toContain('Tiers');
});
