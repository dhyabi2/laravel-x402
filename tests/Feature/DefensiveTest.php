<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Requestway\X402\Config\X402Config;
use Requestway\X402\Core\Exceptions\ConfigurationException;
use Requestway\X402\Core\Protocol\V2Codec;
use Requestway\X402\Enums\SettlementMode;
use Requestway\X402\Facades\X402;
use Requestway\X402\Http\PaymentResponseFactory;
use Requestway\X402\Routing\RequirementsFactory;
use Requestway\X402\Routing\RoutePayment;
use Requestway\X402\Routing\RouteResolver;

/**
 * The guards that only fire when something is badly wrong. They exist so a
 * misconfiguration surfaces as a clear error instead of a wrong charge, and
 * they are tested so they stay that way.
 */
it('reports a route that accepts no networks instead of charging nobody', function (): void {
    $payment = new RoutePayment(
        price: '0.01',
        networks: [],
        payTo: '0x209693Bc6afc0C5328bA36FaF03C514EF312287C',
        scheme: 'exact',
        maxTimeoutSeconds: 60,
        settlement: SettlementMode::BeforeResponse,
        routeUri: '/broken',
        method: 'GET',
    );

    expect(fn (): array => app(RequirementsFactory::class)->accepts($payment))
        ->toThrow(ConfigurationException::class, 'accepts no networks');
});

it('ignores an attribute lookup for a controller class that does not exist', function (): void {
    $route = new RoutingRoute('GET', '/gone', ['controller' => 'App\\Nope@show', 'uses' => 'App\\Nope@show']);

    expect(app(RouteResolver::class)->attributeOptions($route))->toBe([])
        ->and(app(RouteResolver::class)->isPaid($route))->toBeFalse();
});

it('refuses to run without a facilitator URL', function (): void {
    config()->set('x402.facilitator.url', '   ');

    expect(fn (): string => app(X402Config::class)->facilitatorUrl())
        ->toThrow(ConfigurationException::class, 'No facilitator URL configured');
});

it('ignores a tiers config that is not a map', function (): void {
    config()->set('x402.tiers', 'premium');

    $config = app(X402Config::class);

    expect($config->tier('premium'))->toBeNull()->and($config->tiers())->toBe([]);
});

it('ignores a tier whose price is not a number', function (): void {
    config()->set('x402.tiers', ['broken' => ['0.01'], 'fine' => '0.02']);

    expect(app(X402Config::class)->tier('broken'))->toBeNull()
        ->and(app(X402Config::class)->tiers())->toBe(['fine' => '0.02']);
});

it('falls back to the default network when config holds a blank one', function (): void {
    config()->set('x402.network', '');

    expect(app(X402Config::class)->network())->toBe('eip155:84532');
});

it('marks a response private when it carries no cache directives at all', function (): void {
    $factory = app(PaymentResponseFactory::class);
    $response = new Response('x');
    $response->headers->remove('Cache-Control');

    $factory->markPrivate($response);

    expect($response->headers->get('Cache-Control'))->toBe('private');
});

it('passes through when the attribute middleware runs outside a route', function (): void {
    // A request that never matched a route has nothing to charge for.
    config()->set('x402.mode', 'enforce');

    $this->getJson('/does-not-exist')->assertNotFound();
});

it('records no asset when the payment names one this server does not know', function (): void {
    X402::fake();

    Route::get('/premium', fn (): array => ['ok' => true])->middleware('x402:0.01');

    $required = paymentRequired($this->getJson('/premium'));

    // Config changes under the client's feet: the asset it paid in is no
    // longer the one configured for the network.
    config()->set('x402.assets', [
        'eip155:84532' => ['address' => '0x1111111111111111111111111111111111111111'],
    ]);

    $response = $this->getJson('/premium', [
        V2Codec::PAYMENT_SIGNATURE => paymentHeader($required->first(), $required->resource),
    ]);

    // The payment no longer matches what the route advertises, so it is
    // rejected rather than settled against the wrong token.
    $response->assertStatus(402);

    X402::assertNotCharged();
});
