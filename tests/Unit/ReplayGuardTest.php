<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Replay\ReplayGuard;
use Requestway\X402\Core\Replay\ReplayStore;
use Requestway\X402\Core\Testing\PaymentPayloadFactory;
use Requestway\X402\Replay\CacheReplayStore;

function guardRequirements(): PaymentRequirements
{
    return PaymentRequirements::fromArray([
        'scheme' => 'exact',
        'network' => 'eip155:84532',
        'amount' => '10000',
        'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
        'payTo' => '0x209693Bc6afc0C5328bA36FaF03C514EF312287C',
        'maxTimeoutSeconds' => 60,
    ]);
}

function cacheGuard(int $ttl = 60): ReplayGuard
{
    return new ReplayGuard(new CacheReplayStore(Cache::store('array')), $ttl, 'x402:test:');
}

it('claims a payload once and refuses it afterwards', function (): void {
    $guard = cacheGuard();
    $payload = (new PaymentPayloadFactory)->exact(guardRequirements());

    expect($guard->claim($payload))->toBeTrue()
        ->and($guard->claim($payload))->toBeFalse();
});

it('releases a claim so a client can retry a payment that was never charged', function (): void {
    $guard = cacheGuard();
    $payload = (new PaymentPayloadFactory)->exact(guardRequirements());

    $guard->claim($payload);
    $guard->release($payload);

    expect($guard->claim($payload))->toBeTrue();
});

it('treats distinct signed payments as distinct', function (): void {
    $guard = cacheGuard();
    $factory = new PaymentPayloadFactory;

    expect($guard->claim($factory->exact(guardRequirements())))->toBeTrue()
        ->and($guard->claim($factory->exact(guardRequirements())))->toBeTrue();
});

it('prefixes keys with the configured prefix and exposes its retention', function (): void {
    $guard = cacheGuard(1234);
    $payload = (new PaymentPayloadFactory)->exact(guardRequirements());

    expect($guard->key($payload))->toStartWith('x402:test:')
        ->and($guard->key($payload))->toHaveLength(strlen('x402:test:') + 64)
        ->and($guard->ttlSeconds())->toBe(1234);
});

it('passes the retention window to the store', function (): void {
    $store = new class implements ReplayStore
    {
        public int $ttl = 0;

        public function claim(string $key, int $ttlSeconds): bool
        {
            $this->ttl = $ttlSeconds;

            return true;
        }

        public function release(string $key): void {}
    };

    (new ReplayGuard($store, 86400))->claim((new PaymentPayloadFactory)->exact(guardRequirements()));

    expect($store->ttl)->toBe(86400);
});
