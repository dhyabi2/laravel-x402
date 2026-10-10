<?php

declare(strict_types=1);

use HydraLabs\X402\Facades\X402;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/premium', fn (): array => ['data' => 'secret'])
        ->middleware('x402:0.01,description=Market data');
    Route::post('/orders', fn (): array => ['created' => true])->middleware('x402:0.05');
});

it('prints the whole 402 then pay then 200 exchange', function (): void {
    [$status, $output] = runCommand('x402:test', ['uri' => '/premium']);

    expect($status)->toBe(0)
        ->and($output)->toContain('GET /premium', 'fake (nothing is charged)')
        ->and($output)->toContain('1. Unpaid request', 'HTTP 402', 'PAYMENT-REQUIRED')
        ->and($output)->toContain('Base Sepolia', '10000 atomic units ($0.01)')
        ->and($output)->toContain('0x036CbD53842c5426634e7929541eC2318f3dCF7e', '60s')
        ->and($output)->toContain('PAYMENT-SIGNATURE', 'bytes of base64 JSON')
        ->and($output)->toContain('2. Paid request', 'HTTP 200', 'PAYMENT-RESPONSE')
        ->and($output)->toContain('Settled', 'Transaction')
        ->and($output)->toContain('Verifications', 'Settlements', 'The full exchange works');
});

it('decodes the headers it prints', function (): void {
    [, $output] = runCommand('x402:test', ['uri' => '/premium']);

    expect($output)->toContain('"x402Version":2', '"scheme":"exact"', '"amount":"10000"')
        ->and($output)->toContain('"success":true');
});

it('accepts a path with or without a leading slash', function (): void {
    [$status] = runCommand('x402:test', ['uri' => 'premium']);

    expect($status)->toBe(0);
});

it('tests a POST route', function (): void {
    [$status, $output] = runCommand('x402:test', ['uri' => '/orders', '--method' => 'post']);

    expect($status)->toBe(0)->and($output)->toContain('POST /orders', '50000 atomic units ($0.05)');
});

it('pays from a given address', function (): void {
    [, $output] = runCommand('x402:test', [
        'uri' => '/premium',
        '--payer' => '0x1111111111111111111111111111111111111111',
    ]);

    expect($output)->toContain('0x1111111111111111111111111111111111111111');
});

it('sends extra request headers', function (): void {
    config()->set('x402.bypass.api_keys.keys', ['secret-key']);

    [$status, $output] = runCommand('x402:test', [
        'uri' => '/premium',
        '--header' => ['X-API-Key: secret-key', 'malformed-header'],
    ]);

    // The key bypasses payment, so there is no 402 to pay — which the command
    // reports rather than pretending to have tested a payment.
    expect($status)->toBe(1)->and($output)->toContain('Expected 402, got 200');
});

it('picks a network when a route accepts several', function (): void {
    config()->set('x402.additional_networks', ['base']);

    [$status, $output] = runCommand('x402:test', ['uri' => '/premium', '--network' => 'base']);

    expect($status)->toBe(0)->and($output)->toContain('Base', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913');
});

it('refuses a network the route does not accept', function (): void {
    [$status, $output] = runCommand('x402:test', ['uri' => '/premium', '--network' => 'base']);

    expect($status)->toBe(1)->and($output)->toContain('does not accept payments on Base');
});

it('checks the underpayment path', function (): void {
    [$status, $output] = runCommand('x402:test', ['uri' => '/premium', '--underpay' => true]);

    expect($status)->toBe(0)->and($output)->toContain('Rejected as expected with HTTP 402');
});

it('checks the settlement failure path', function (): void {
    [$status, $output] = runCommand('x402:test', ['uri' => '/premium', '--fail-settlement' => true]);

    expect($status)->toBe(0)
        ->and($output)->toContain('Rejected as expected with HTTP 402')
        ->and($output)->toContain('Settled', 'Reason');
});

it('can print the raw base64 header', function (): void {
    [, $output] = runCommand('x402:test', ['uri' => '/premium', '--raw' => true]);

    expect($output)->toContain('eyJ');
});

it('explains when a route is not protected', function (): void {
    Route::get('/open', fn (): array => ['free' => true]);

    [$status, $output] = runCommand('x402:test', ['uri' => '/open']);

    expect($status)->toBe(1)
        ->and($output)->toContain('Expected 402, got 200', 'Is the x402 middleware on this route');
});

it('explains when the route does not exist', function (): void {
    [$status, $output] = runCommand('x402:test', ['uri' => '/nope']);

    expect($status)->toBe(1)->and($output)->toContain('Expected 402, got 404');
});

it('explains when the package is switched off', function (): void {
    config()->set('x402.mode', 'off');

    [$status, $output] = runCommand('x402:test', ['uri' => '/premium']);

    expect($status)->toBe(1)->and($output)->toContain('X402_MODE=enforce');
});

it('leaves a fake facilitator in place that the test suite can assert on', function (): void {
    runCommand('x402:test', ['uri' => '/premium']);

    expect(X402::isFaked())->toBeTrue();

    X402::assertSettledFor('/premium', '0.01');
});
