<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Requestway\X402\Core\Protocol\V2Codec;
use Requestway\X402\Http\PaymentResponseFactory;
use Requestway\X402\Tests\TestCase;

beforeEach(function (): void {
    Route::get('/premium', fn (): array => ['data' => 'secret'])
        ->middleware('x402:0.01')
        ->name('premium');
});

it('answers an unpaid request with 402 and the PAYMENT-REQUIRED header', function (): void {
    $response = $this->getJson('/premium');

    $response->assertStatus(402)
        ->assertHeader('Cache-Control', PaymentResponseFactory::CACHE_CONTROL);

    expect($response->headers->get(V2Codec::PAYMENT_REQUIRED))->toBeString()->not->toBeEmpty();
});

it('emits exactly the PaymentRequired shape the spec defines', function (): void {
    $required = paymentRequired($this->getJson('/premium'));

    expect($required->x402Version)->toBe(2)
        ->and($required->error)->toContain('PAYMENT-SIGNATURE')
        ->and($required->resource->url)->toBe('http://localhost/premium')
        ->and($required->resource->mimeType)->toBe('application/json')
        ->and($required->accepts)->toHaveCount(1);

    $requirements = $required->first();

    expect($requirements->scheme)->toBe('exact')
        ->and($requirements->network)->toBe('eip155:84532')
        ->and($requirements->amount)->toBe('10000')
        ->and($requirements->asset)->toBe('0x036CbD53842c5426634e7929541eC2318f3dCF7e')
        ->and($requirements->payTo)->toBe(TestCase::PAY_TO)
        ->and($requirements->maxTimeoutSeconds)->toBe(60)
        ->and($requirements->extra)->toBe(['name' => 'USDC', 'version' => '2']);
});

it('matches the specification example byte for byte given the same inputs', function (): void {
    config()->set('x402.network', 'eip155:84532');

    Route::get('/premium-data', fn (): array => [])
        ->middleware('x402:0.01');

    $required = paymentRequired($this->getJson('/premium-data'));
    $golden = fixture('payment_required');

    expect($required->first()->toArray())->toBe($golden['accepts'][0]);
});

it('repeats the requirements in the body so a browser sees something sensible', function (): void {
    $response = $this->get('/premium', ['Accept' => 'text/html']);

    $response->assertStatus(402)
        ->assertJsonPath('x402Version', 2)
        ->assertJsonPath('accepts.0.amount', '10000')
        ->assertJsonPath('resource.url', 'http://localhost/premium');

    expect($response->json('error'))->toContain('$0.01');
});

it('advertises one payment option per accepted network', function (): void {
    config()->set('x402.additional_networks', ['base', 'eip155:42161']);

    $required = paymentRequired($this->getJson('/premium'));

    expect($required->accepts)->toHaveCount(3)
        ->and(array_map(fn ($r): string => $r->network, $required->accepts))
        ->toBe(['eip155:84532', 'eip155:8453', 'eip155:42161'])
        ->and($required->accepts[1]->asset)->toBe('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913')
        ->and($required->accepts[1]->extra['name'])->toBe('USD Coin');
});

it('carries the route description, mime type and service metadata', function (): void {
    config()->set('x402.discovery.service_name', 'Example Data');
    config()->set('x402.discovery.tags', ['market-data', 'finance']);
    config()->set('x402.discovery.icon_url', 'https://api.test/icon.png');

    Route::get('/described', fn (): array => [])
        ->middleware('x402:0.01,description=Market snapshot,mime=text/csv');

    $required = paymentRequired($this->getJson('/described'));

    expect($required->resource->description)->toBe('Market snapshot')
        ->and($required->resource->mimeType)->toBe('text/csv')
        ->and($required->resource->serviceName)->toBe('Example Data')
        ->and($required->resource->tags)->toBe(['market-data', 'finance'])
        ->and($required->resource->iconUrl)->toBe('https://api.test/icon.png');
});

it('includes the query string in the resource url, because it changes what is being bought', function (): void {
    $required = paymentRequired($this->getJson('/premium?symbol=ETH'));

    expect($required->resource->url)->toBe('http://localhost/premium?symbol=ETH');
});

it('scales the advertised amount with the asset decimals', function (): void {
    config()->set('x402.assets', ['eip155:84532' => ['decimals' => 18]]);

    expect(paymentRequired($this->getJson('/premium'))->first()->amount)->toBe('10000000000000000');
});

it('caps the advertised timeout at the configured maximum', function (): void {
    config()->set('x402.settlement.max_timeout_seconds', 30);

    Route::get('/slow', fn (): array => [])->middleware('x402:0.01,timeout=600');

    expect(paymentRequired($this->getJson('/slow'))->first()->maxTimeoutSeconds)->toBe(30);
});

it('honours a shorter per-route timeout', function (): void {
    Route::get('/quick', fn (): array => [])->middleware('x402:0.01,timeout=15');

    expect(paymentRequired($this->getJson('/quick'))->first()->maxTimeoutSeconds)->toBe(15);
});

it('fails loudly when no wallet is configured, rather than charging nobody', function (): void {
    config()->set('x402.pay_to', null);

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/premium'))
        ->toThrow(Requestway\X402\Core\Exceptions\ConfigurationException::class, 'No x402 wallet configured');
});

it('fails loudly when the wallet address is malformed', function (): void {
    config()->set('x402.pay_to', '0xnope');

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/premium'))
        ->toThrow(Requestway\X402\Core\Exceptions\ConfigurationException::class, 'EVM address');
});

it('fails loudly when a route has no price at all', function (): void {
    Route::get('/priceless', fn (): array => [])->middleware('x402');

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/priceless'))
        ->toThrow(Requestway\X402\Core\Exceptions\ConfigurationException::class, 'No price configured');
});

it('fails loudly when a route accepts a network with no asset', function (): void {
    Route::get('/fuji', fn (): array => [])->middleware('x402:0.01,network=eip155:43113');

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/fuji'))
        ->toThrow(Requestway\X402\Core\Exceptions\ConfigurationException::class, 'no asset is configured');
});
