<?php

declare(strict_types=1);

use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Requestway\X402\Routing\RouteResolver;
use Requestway\X402\Tests\Support\TestController;

/**
 * Production apps run `php artisan route:cache`, which serializes each route's
 * action array and rebuilds the collection from it. The paid() macro keeps its
 * options there, so a round trip through that serialization has to preserve
 * them — otherwise a cached app would silently lose every macro-set price.
 */
function compileRoutes(): CompiledRouteCollection
{
    $routes = Route::getRoutes();

    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }

    $compiled = $routes->compile();

    // The same round trip route:cache performs: serialize, then rebuild.
    /** @var array{compiled: array<string, mixed>, attributes: array<string, mixed>} $restored */
    $restored = unserialize(serialize($compiled));

    return tap(
        new CompiledRouteCollection($restored['compiled'], $restored['attributes']),
        fn (CompiledRouteCollection $collection) => $collection
            ->setRouter(app('router'))
            ->setContainer(app())
    );
}

function cachedRoute(string $uri): RoutingRoute
{
    foreach (compileRoutes()->getRoutes() as $route) {
        if ($route->uri() === ltrim($uri, '/')) {
            return $route;
        }
    }

    throw new RuntimeException(sprintf('No route for %s survived compilation.', $uri));
}

it('keeps the paid() macro options through route serialization', function (): void {
    Route::get('/macro', fn (): array => [])->paid('0.03', [
        'description' => 'From the macro',
        'mime_type' => 'text/csv',
    ]);

    $route = cachedRoute('/macro');

    expect($route->getAction('x402'))->toBe([
        'description' => 'From the macro',
        'mime_type' => 'text/csv',
        'price' => '0.03',
    ]);
});

it('resolves a cached macro route to the same price', function (): void {
    Route::get('/macro', fn (): array => [])->paid('0.03');

    $payment = app(RouteResolver::class)->resolveForRoute(cachedRoute('/macro'));

    expect($payment?->price)->toBe('0.03');
});

it('keeps middleware parameters through route serialization', function (): void {
    Route::get('/middleware', fn (): array => [])->middleware('x402:0.07,description=Cached');

    $payment = app(RouteResolver::class)->resolveForRoute(cachedRoute('/middleware'));

    expect($payment?->price)->toBe('0.07')->and($payment?->description)->toBe('Cached');
});

it('still reads attributes from a cached controller route', function (): void {
    Route::get('/attribute', [TestController::class, 'show'])->middleware('x402');

    $payment = app(RouteResolver::class)->resolveForRoute(cachedRoute('/attribute'));

    expect($payment?->price)->toBe('0.02')->and($payment?->description)->toBe('Attribute priced');
});

it('still recognises cached routes as paid', function (): void {
    Route::get('/macro', fn (): array => [])->paid('0.03');
    Route::get('/middleware', fn (): array => [])->middleware('x402:0.01');
    Route::get('/attribute', [TestController::class, 'show'])->middleware('x402');
    Route::get('/free', fn (): array => []);

    $resolver = app(RouteResolver::class);

    expect($resolver->isPaid(cachedRoute('/macro')))->toBeTrue()
        ->and($resolver->isPaid(cachedRoute('/middleware')))->toBeTrue()
        ->and($resolver->isPaid(cachedRoute('/attribute')))->toBeTrue()
        ->and($resolver->isPaid(cachedRoute('/free')))->toBeFalse();
});
