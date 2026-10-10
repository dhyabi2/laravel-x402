<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Requestway\X402\Tests\Support\TestController;

beforeEach(function (): void {
    Route::get('/premium', fn (): array => [])->middleware('x402:0.01,description=Market data,mime=application/json');
    Route::post('/reports', fn (): array => [])->middleware('x402:premium');
    Route::get('/free', fn (): array => []);
});

it('serves the paid route catalogue at the well-known path', function (): void {
    $response = $this->getJson('/.well-known/x402');

    $response->assertOk()
        ->assertJsonPath('x402Version', 2)
        ->assertJsonCount(2, 'items');

    $items = collect($response->json('items'))->keyBy('resource');

    expect($items->keys()->all())->toContain('http://localhost/premium', 'http://localhost/reports')
        ->and($items['http://localhost/premium']['type'])->toBe('http')
        ->and($items['http://localhost/premium']['method'])->toBe('GET')
        ->and($items['http://localhost/premium']['description'])->toBe('Market data')
        ->and($items['http://localhost/premium']['mimeType'])->toBe('application/json')
        ->and($items['http://localhost/premium']['accepts'][0]['amount'])->toBe('10000')
        ->and($items['http://localhost/reports']['accepts'][0]['amount'])->toBe('100000')
        ->and($items['http://localhost/reports']['method'])->toBe('POST');
});

it('uses the discovery shape from the specification so Bazaar clients can read it', function (): void {
    $item = $this->getJson('/.well-known/x402')->json('items.0');

    expect($item)->toHaveKeys(['resource', 'type', 'x402Version', 'accepts', 'lastUpdated'])
        ->and($item['accepts'][0])->toHaveKeys(['scheme', 'network', 'amount', 'asset', 'payTo', 'maxTimeoutSeconds'])
        ->and($this->getJson('/.well-known/x402')->json('pagination'))
        ->toHaveKeys(['limit', 'offset', 'total']);
});

it('leaves unpaid routes out of the catalogue', function (): void {
    $resources = collect($this->getJson('/.well-known/x402')->json('items'))->pluck('resource');

    expect($resources)->not->toContain('http://localhost/free');
});

it('includes attribute-protected and macro-protected routes', function (): void {
    Route::get('/attr', [TestController::class, 'show'])->middleware('x402');
    Route::get('/macro', fn (): array => [])->paid('0.07');

    $items = collect($this->getJson('/.well-known/x402')->json('items'))->keyBy('resource');

    expect($items['http://localhost/attr']['accepts'][0]['amount'])->toBe('20000')
        ->and($items['http://localhost/macro']['accepts'][0]['amount'])->toBe('70000');
});

it('reports the service identity', function (): void {
    config()->set('x402.discovery.service_name', 'Example Data');
    config()->set('x402.discovery.icon_url', 'https://api.test/icon.png');
    config()->set('x402.discovery.tags', ['finance']);

    expect($this->getJson('/.well-known/x402')->json('service'))->toBe([
        'name' => 'Example Data',
        'iconUrl' => 'https://api.test/icon.png',
        'tags' => ['finance'],
    ]);
});

it('lists every accepted network for a route', function (): void {
    config()->set('x402.additional_networks', ['base']);

    $accepts = collect($this->getJson('/.well-known/x402')->json('items'))
        ->firstWhere('resource', 'http://localhost/premium')['accepts'];

    expect($accepts)->toHaveCount(2)
        ->and(array_column($accepts, 'network'))->toBe(['eip155:84532', 'eip155:8453']);
});

it('omits a misconfigured route rather than serving broken requirements', function (): void {
    Route::get('/broken', fn (): array => [])->middleware('x402:0.01,network=eip155:43113');

    $resources = collect($this->getJson('/.well-known/x402')->json('items'))->pluck('resource');

    expect($resources)->not->toContain('http://localhost/broken')
        ->and($resources)->toContain('http://localhost/premium');
});

it('caches the catalogue when a TTL is configured', function (): void {
    config()->set('x402.discovery.cache_ttl', 60);

    $this->getJson('/.well-known/x402')->assertOk()->assertJsonCount(2, 'items');

    Route::get('/new', fn (): array => [])->middleware('x402:0.01');

    $this->getJson('/.well-known/x402')
        ->assertJsonCount(2, 'items')
        ->assertHeader('Cache-Control', 'max-age=60, public');
});

it('can be switched off', function (): void {
    $this->configOverrides = ['x402.discovery.enabled' => false];

    $this->refreshApplication();

    $this->getJson('/.well-known/x402')->assertNotFound();
});

it('can be served from a different path', function (): void {
    $this->configOverrides = ['x402.discovery.path' => 'api/x402-catalogue'];

    $this->refreshApplication();

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    $this->getJson('/api/x402-catalogue')->assertOk()->assertJsonPath('x402Version', 2);
});

it('advertises nothing while the package is switched off', function (): void {
    config()->set('x402.mode', 'off');

    $response = $this->getJson('/.well-known/x402');

    $response->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('pagination.total', 0);
});

it('still advertises prices in observe mode, where agents are expected to call', function (): void {
    config()->set('x402.mode', 'observe');

    $this->getJson('/.well-known/x402')->assertOk()->assertJsonCount(2, 'items');
});
