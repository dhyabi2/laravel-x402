<?php

declare(strict_types=1);

use HydraLabs\X402\Facades\X402;
use HydraLabs\X402\Http\Middleware\RequirePayment;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    X402::fake();

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');
    Route::get('/members', fn (): array => ['data' => 'secret'])->middleware('x402:0.25,free-for-auth');
});

it('charges an anonymous visitor on a free-for-auth route', function (): void {
    $this->getJson('/members')->assertStatus(402);
});

it('serves a signed-in user for free on a free-for-auth route', function (): void {
    $response = $this->actingAs(new User)->getJson('/members');

    $response->assertOk()->assertHeader(RequirePayment::BYPASS_HEADER, 'authenticated');

    X402::assertNothingVerified();
});

it('still charges a signed-in user on a route that is not free-for-auth', function (): void {
    $this->actingAs(new User)->getJson('/premium')->assertStatus(402);
});

it('exempts signed-in users everywhere when configured to', function (): void {
    config()->set('x402.bypass.auth.always', true);

    $this->actingAs(new User)->getJson('/premium')
        ->assertOk()
        ->assertHeader(RequirePayment::BYPASS_HEADER, 'authenticated');
});

it('checks only the configured guards', function (): void {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
    config()->set('x402.bypass.auth.guards', ['admin']);

    $this->actingAs(new User)->getJson('/members')->assertStatus(402);

    config()->set('x402.bypass.auth.guards', ['web']);

    $this->actingAs(new User)->getJson('/members')->assertOk();
});

it('charges rather than crashing when config names a guard the app does not define', function (): void {
    config()->set('x402.bypass.auth.guards', ['nonexistent']);

    $this->actingAs(new User)->getJson('/members')->assertStatus(402);
});

it('exempts an allowlisted IP', function (): void {
    config()->set('x402.bypass.ips', ['127.0.0.1']);

    $this->getJson('/premium')->assertOk()->assertHeader(RequirePayment::BYPASS_HEADER, 'ip-allowlist');
});

it('exempts an allowlisted CIDR range', function (): void {
    config()->set('x402.bypass.ips', ['10.0.0.0/8', '127.0.0.0/24']);

    $this->getJson('/premium')->assertOk();
});

it('charges an IP outside the allowlist', function (): void {
    config()->set('x402.bypass.ips', ['10.0.0.1']);

    $this->getJson('/premium')->assertStatus(402);
});

it('exempts a valid API key', function (): void {
    config()->set('x402.bypass.api_keys.keys', ['secret-key', 'other-key']);

    $this->getJson('/premium', ['X-API-Key' => 'other-key'])
        ->assertOk()
        ->assertHeader(RequirePayment::BYPASS_HEADER, 'api-key');
});

it('charges a wrong, empty or missing API key', function (?string $key): void {
    config()->set('x402.bypass.api_keys.keys', ['secret-key']);

    $headers = $key === null ? [] : ['X-API-Key' => $key];

    $this->getJson('/premium', $headers)->assertStatus(402);
})->with([null, '', 'wrong-key', 'secret-key ']);

it('reads the API key from a configurable header', function (): void {
    config()->set('x402.bypass.api_keys.header', 'X-Customer-Token');
    config()->set('x402.bypass.api_keys.keys', ['secret-key']);

    $this->getJson('/premium', ['X-API-Key' => 'secret-key'])->assertStatus(402);
    $this->getJson('/premium', ['X-Customer-Token' => 'secret-key'])->assertOk();
});

it('exempts requests matching a custom closure', function (): void {
    X402::bypassUsing(fn ($request): bool => $request->hasHeader('X-Partner'));

    $this->getJson('/premium')->assertStatus(402);

    $this->getJson('/premium', ['X-Partner' => 'acme'])
        ->assertOk()
        ->assertHeader(RequirePayment::BYPASS_HEADER, 'custom');
});

it('gives the closure the resolved route payment', function (): void {
    $seen = null;

    X402::bypassUsing(function ($request, $route) use (&$seen): bool {
        $seen = $route;

        return false;
    });

    $this->getJson('/members')->assertStatus(402);

    expect($seen->price)->toBe('0.25')->and($seen->freeForAuth)->toBeTrue();
});

it('treats a zero price as free', function (): void {
    Route::get('/free', fn (): array => ['data' => 'free'])->middleware('x402:0');

    $this->getJson('/free')->assertOk()->assertHeader(RequirePayment::BYPASS_HEADER, 'free');
});

it('can opt a route out of a global auth exemption', function (): void {
    config()->set('x402.bypass.auth.always', true);

    Route::get('/always-paid', fn (): array => [])->middleware('x402:0.01,paid-for-auth');

    $this->actingAs(new User)->getJson('/always-paid')->assertStatus(402);
});
