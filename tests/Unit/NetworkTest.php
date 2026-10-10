<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Exceptions\ConfigurationException;
use HydraLabs\X402\Core\Networks\Network;

it('normalizes v1 aliases to CAIP-2', function (string $alias, string $caip2): void {
    expect(Network::normalize($alias))->toBe($caip2);
})->with([
    ['base', 'eip155:8453'],
    ['Base-Sepolia', 'eip155:84532'],
    ['base-sepolia', 'eip155:84532'],
    ['avalanche', 'eip155:43114'],
    ['polygon', 'eip155:137'],
    ['eip155:8453', 'eip155:8453'],
    ['solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp', 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'],
]);

it('rejects identifiers that are neither CAIP-2 nor a known alias', function (string $network): void {
    expect(fn (): string => Network::normalize($network))->toThrow(ConfigurationException::class);
})->with(['', '   ', 'ethereum-classic', 'eip155', '8453', 'EIP155:8453']);

it('knows which networks are testnets', function (): void {
    expect(Network::isTestnet('base-sepolia'))->toBeTrue()
        ->and(Network::isTestnet('eip155:84532'))->toBeTrue()
        ->and(Network::isTestnet('eip155:8453'))->toBeFalse()
        ->and(Network::isTestnet('base'))->toBeFalse();
});

it('labels known networks and falls back to the identifier', function (): void {
    expect(Network::label('eip155:8453'))->toBe('Base')
        ->and(Network::label('eip155:84532'))->toBe('Base Sepolia')
        ->and(Network::label('eip155:999999'))->toBe('eip155:999999');
});

it('reads the namespace', function (): void {
    expect(Network::namespace('eip155:8453'))->toBe('eip155')
        ->and(Network::namespace('solana:abc'))->toBe('solana')
        ->and(Network::isEvm('eip155:8453'))->toBeTrue()
        ->and(Network::isEvm('solana:abc'))->toBeFalse();
});
