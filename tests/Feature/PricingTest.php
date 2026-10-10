<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Requestway\X402\Facades\X402;
use Requestway\X402\Tests\Support\FlatRatePriceResolver;
use Requestway\X402\Tests\Support\PaidController;
use Requestway\X402\Tests\Support\TestController;

beforeEach(function (): void {
    X402::fake();
});

it('prices a route from the middleware parameter', function (): void {
    Route::get('/a', fn (): array => [])->middleware('x402:0.03');

    expect(paymentRequired($this->getJson('/a'))->first()->amount)->toBe('30000');
});

it('accepts a dollar sign in the middleware parameter', function (): void {
    Route::get('/a', fn (): array => [])->middleware('x402:$0.03');

    expect(paymentRequired($this->getJson('/a'))->first()->amount)->toBe('30000');
});

it('resolves a named price tier', function (string $tier, string $atomic): void {
    Route::get('/tiered', fn (): array => [])->middleware('x402:'.$tier);

    expect(paymentRequired($this->getJson('/tiered'))->first()->amount)->toBe($atomic);
})->with([
    ['micro', '1000'],
    ['standard', '10000'],
    ['premium', '100000'],
]);

it('resolves a tier defined at runtime', function (): void {
    config()->set('x402.tiers.enterprise', '5.00');

    Route::get('/enterprise', fn (): array => [])->middleware('x402:enterprise');

    expect(paymentRequired($this->getJson('/enterprise'))->first()->amount)->toBe('5000000');
});

it('falls back to the configured default price', function (): void {
    config()->set('x402.defaults.price', '0.07');

    Route::get('/default', fn (): array => [])->middleware('x402');

    expect(paymentRequired($this->getJson('/default'))->first()->amount)->toBe('70000');
});

it('prices a route with the paid() route macro', function (): void {
    Route::get('/macro', fn (): array => ['data' => 'macro'])->paid('0.04');

    $required = paymentRequired($this->getJson('/macro'));

    expect($required->first()->amount)->toBe('40000');

    $this->getJson('/macro', payment($required->first()))->assertOk();

    X402::assertSettledFor('/macro', '0.04');
});

it('passes options through the paid() macro', function (): void {
    Route::get('/macro-options', fn (): array => [])->paid('0.04', [
        'description' => 'From the macro',
        'mime_type' => 'text/csv',
        'free_for_auth' => true,
    ]);

    $required = paymentRequired($this->getJson('/macro-options'));

    expect($required->resource->description)->toBe('From the macro')
        ->and($required->resource->mimeType)->toBe('text/csv');
});

it('combines paid() with an explicitly chained middleware price', function (): void {
    Route::get('/macro-override', fn (): array => [])->paid('0.04')->middleware('x402:0.09');

    expect(paymentRequired($this->getJson('/macro-override'))->first()->amount)->toBe('90000');
});

it('prices a route from a #[RequiresPayment] attribute on the action', function (): void {
    Route::get('/attr', [TestController::class, 'show'])->middleware('x402');

    $required = paymentRequired($this->getJson('/attr'));

    expect($required->first()->amount)->toBe('20000')
        ->and($required->resource->description)->toBe('Attribute priced')
        ->and($required->resource->mimeType)->toBe('text/csv');

    $this->getJson('/attr', payment($required->first()))
        ->assertOk()
        ->assertExactJson(['data' => 'from attribute']);
});

it('resolves a tier named in an attribute', function (): void {
    Route::get('/attr-tier', [TestController::class, 'tiered'])->middleware('x402');

    $required = paymentRequired($this->getJson('/attr-tier'));

    expect($required->first()->amount)->toBe('100000')
        ->and($required->resource->tags)->toBe(['reports']);
});

it('applies attributes app-wide with the attribute middleware', function (): void {
    Route::middleware('x402.attributes')->group(function (): void {
        Route::get('/auto-paid', [TestController::class, 'show']);
        Route::get('/auto-free', [TestController::class, 'free']);
    });

    expect(paymentRequired($this->getJson('/auto-paid'))->first()->amount)->toBe('20000');

    $this->getJson('/auto-free')->assertOk()->assertExactJson(['data' => 'free']);
});

it('does not charge twice when both middlewares are present', function (): void {
    Route::middleware('x402.attributes')->group(function (): void {
        Route::get('/both', [TestController::class, 'show'])->middleware('x402');
    });

    $required = paymentRequired($this->getJson('/both'));

    $this->getJson('/both', payment($required->first()))->assertOk();

    X402::assertVerified(1);
    X402::assertSettled(1);
});

it('lets a method attribute override a class attribute', function (): void {
    Route::middleware('x402.attributes')->group(function (): void {
        Route::get('/class-priced', [PaidController::class, 'index']);
        Route::get('/method-priced', [PaidController::class, 'expensive']);
    });

    expect(paymentRequired($this->getJson('/class-priced'))->first()->amount)->toBe('50000')
        ->and(paymentRequired($this->getJson('/method-priced'))->first()->amount)->toBe('500000')
        ->and(paymentRequired($this->getJson('/class-priced'))->resource->description)->toBe('Class default');
});

it('lets the middleware parameter override an attribute', function (): void {
    Route::get('/override', [TestController::class, 'show'])->middleware('x402:0.99');

    expect(paymentRequired($this->getJson('/override'))->first()->amount)->toBe('990000');
});

it('prices dynamically from a closure', function (): void {
    X402::priceUsing(fn ($request): ?string => $request->query('size') === 'large' ? '0.50' : null);

    Route::get('/dynamic', fn (): array => [])->middleware('x402:0.01');

    expect(paymentRequired($this->getJson('/dynamic'))->first()->amount)->toBe('10000')
        ->and(paymentRequired($this->getJson('/dynamic?size=large'))->first()->amount)->toBe('500000');
});

it('gives the closure the resolved route payment', function (): void {
    X402::priceUsing(fn ($request, $route): string => $route->routeName === 'reports' ? '2.00' : '0.01');

    Route::get('/reports', fn (): array => [])->middleware('x402:0.01')->name('reports');

    expect(paymentRequired($this->getJson('/reports'))->first()->amount)->toBe('2000000');
});

it('prices dynamically from a resolver class', function (): void {
    X402::priceUsing(FlatRatePriceResolver::class);

    Route::get('/bulk', fn (): array => [])->middleware('x402:0.01');

    expect(paymentRequired($this->getJson('/bulk'))->first()->amount)->toBe('10000')
        ->and(paymentRequired($this->getJson('/bulk?bulk=yes'))->first()->amount)->toBe('1000000');
});

it('prices dynamically from a resolver instance', function (): void {
    X402::priceUsing(new FlatRatePriceResolver);

    Route::get('/bulk', fn (): array => [])->middleware('x402:0.01');

    expect(paymentRequired($this->getJson('/bulk?bulk=yes'))->first()->amount)->toBe('1000000');
});

it('lets a dynamic price name a tier', function (): void {
    X402::priceUsing(fn (): string => 'premium');

    Route::get('/dynamic-tier', fn (): array => [])->middleware('x402:0.01');

    expect(paymentRequired($this->getJson('/dynamic-tier'))->first()->amount)->toBe('100000');
});

it('charges the dynamically resolved price, not the advertised default', function (): void {
    X402::priceUsing(fn (): string => '0.50');

    Route::get('/dynamic', fn (): array => ['ok' => true])->middleware('x402:0.01');

    $required = paymentRequired($this->getJson('/dynamic'));

    $this->getJson('/dynamic', payment($required->first()))->assertOk();

    X402::assertSettledFor('/dynamic', '0.50');
});

it('rejects a nonsense price loudly', function (): void {
    Route::get('/bad', fn (): array => [])->middleware('x402:free');

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/bad'))
        ->toThrow(Requestway\X402\Core\Exceptions\InvalidAmountException::class);
});

it('rejects an unknown middleware option loudly', function (): void {
    Route::get('/bad', fn (): array => [])->middleware('x402:0.01,colour=red');

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/bad'))
        ->toThrow(Requestway\X402\Core\Exceptions\ConfigurationException::class, 'Unknown x402 middleware option');
});

it('accepts a per-route wallet', function (): void {
    $other = '0x0000000000000000000000000000000000000abc';

    Route::get('/affiliate', fn (): array => [])->middleware('x402:0.01,pay_to='.$other);

    expect(paymentRequired($this->getJson('/affiliate'))->first()->payTo)->toBe($other);
});

it('rejects a malformed per-route wallet', function (): void {
    Route::get('/affiliate', fn (): array => [])->middleware('x402:0.01,pay_to=nope');

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/affiliate'))
        ->toThrow(Requestway\X402\Core\Exceptions\ConfigurationException::class, 'EVM address');
});

it('accepts a per-route network list', function (): void {
    Route::get('/multi', fn (): array => [])->middleware('x402:0.01,networks=base|base-sepolia');

    $accepts = paymentRequired($this->getJson('/multi'))->accepts;

    expect(array_map(fn ($r): string => $r->network, $accepts))->toBe(['eip155:8453', 'eip155:84532']);
});

it('charges only once when the middleware is listed twice', function (): void {
    Route::get('/twice', fn (): array => ['ok' => true])->middleware(['x402:0.01', 'x402:0.01']);

    $required = paymentRequired($this->getJson('/twice'));

    $this->getJson('/twice', payment($required->first()))->assertOk();

    X402::assertVerified(1);
    X402::assertSettled(1);
});
