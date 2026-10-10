<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Protocol\V2Codec;
use HydraLabs\X402\Facades\X402;
use HydraLabs\X402\Http\PaymentResponseFactory;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    X402::fake();
});

it('never lets a shared cache store a 402', function (): void {
    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    expect($this->getJson('/premium')->headers->get('Cache-Control'))
        ->toBe(PaymentResponseFactory::CACHE_CONTROL);
});

it('adds private to a paid response that already has cache directives', function (): void {
    Route::get('/cached', fn () => response()->json(['data' => 'x'])
        ->header('Cache-Control', 'max-age=60'))->middleware('x402:0.01');

    $requirements = paymentRequired($this->getJson('/cached'))->first();

    expect($this->getJson('/cached', payment($requirements))->headers->get('Cache-Control'))
        ->toBe('max-age=60, private');
});

it('does not repeat a directive the response already carries', function (string $cacheControl): void {
    Route::get('/cached', fn () => response()->json(['data' => 'x'])
        ->header('Cache-Control', $cacheControl))->middleware('x402:0.01');

    $requirements = paymentRequired($this->getJson('/cached'))->first();
    $header = (string) $this->getJson('/cached', payment($requirements))->headers->get('Cache-Control');

    expect(substr_count($header, 'private'))->toBeLessThanOrEqual(1)
        ->and($header)->not->toContain('public');
})->with(['private, max-age=60', 'no-store', 'private']);

it('marks a deferred-settlement response private too', function (): void {
    config()->set('x402.settlement.mode', 'after_response');

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    expect($this->getJson('/premium', payment($requirements))->headers->get('Cache-Control'))
        ->toContain('private');
});

it('puts the settlement receipt on the response that carries the resource', function (): void {
    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $response = $this->getJson('/premium', payment($requirements));

    expect($response->headers->has(V2Codec::PAYMENT_RESPONSE))->toBeTrue()
        ->and($response->headers->has(V2Codec::PAYMENT_REQUIRED))->toBeFalse();
});
