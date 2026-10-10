<?php

declare(strict_types=1);

use Requestway\X402\Core\Assets\Asset;
use Requestway\X402\Core\Assets\AssetRegistry;
use Requestway\X402\Core\Exceptions\ConfigurationException;
use Requestway\X402\Core\Networks\Network;

it('ships the canonical USDC addresses from the specification', function (): void {
    $registry = new AssetRegistry;

    expect($registry->for('eip155:8453')->address)->toBe('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913')
        ->and($registry->for('eip155:84532')->address)->toBe('0x036CbD53842c5426634e7929541eC2318f3dCF7e')
        ->and($registry->for('base')->address)->toBe('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913')
        ->and($registry->for('eip155:84532')->decimals)->toBe(6)
        ->and($registry->for('eip155:84532')->symbol)->toBe('USDC');
});

it('ships the EIP-712 domain values the exact scheme requires', function (): void {
    $extra = (new AssetRegistry)->for('eip155:8453')->requirementsExtra();

    expect($extra)->toBe(['name' => 'USD Coin', 'version' => '2']);
});

it('merges a partial override over a shipped default', function (): void {
    $registry = new AssetRegistry([
        'base-sepolia' => ['decimals' => 18],
    ]);

    $asset = $registry->for('eip155:84532');

    expect($asset->decimals)->toBe(18)
        ->and($asset->address)->toBe('0x036CbD53842c5426634e7929541eC2318f3dCF7e')
        ->and($asset->symbol)->toBe('USDC');
});

it('accepts an asset for a network it has never heard of', function (): void {
    $registry = new AssetRegistry([
        'eip155:7777' => [
            'address' => '0x1111111111111111111111111111111111111111',
            'decimals' => 2,
            'symbol' => 'TEST',
            'name' => 'Test Coin',
            'version' => '1',
            'extra' => ['assetTransferMethod' => 'permit2'],
        ],
    ]);

    $asset = $registry->for('eip155:7777');

    expect($asset->symbol)->toBe('TEST')
        ->and($asset->requirementsExtra())->toBe([
            'name' => 'Test Coin',
            'version' => '1',
            'assetTransferMethod' => 'permit2',
        ]);
});

it('can be built with no defaults at all', function (): void {
    $registry = new AssetRegistry([], withDefaults: false);

    expect($registry->networks())->toBe([])->and($registry->has('eip155:8453'))->toBeFalse();
});

it('tells you which network has no asset instead of guessing', function (): void {
    $registry = new AssetRegistry;

    expect($registry->has('eip155:43113'))->toBeFalse()
        ->and(fn (): Asset => $registry->for('eip155:43113'))
        ->toThrow(ConfigurationException::class, 'No asset configured for network "eip155:43113"');
});

it('finds an asset by address, ignoring checksum casing', function (): void {
    $registry = new AssetRegistry;

    expect($registry->find('eip155:84532', '0x036cbd53842c5426634e7929541ec2318f3dcf7e'))->not->toBeNull()
        ->and($registry->find('eip155:84532', '0x0000000000000000000000000000000000000001'))->toBeNull()
        ->and($registry->find('eip155:43113', '0x036CbD53842c5426634e7929541eC2318f3dCF7e'))->toBeNull();
});

it('rejects an asset definition that is incomplete or invalid', function (array $definition, string $message): void {
    expect(fn (): AssetRegistry => new AssetRegistry(['eip155:1' => $definition]))
        ->toThrow(ConfigurationException::class, $message);
})->with([
    [['decimals' => 6, 'symbol' => 'X', 'name' => 'X', 'address' => 'nope'], 'not a valid EVM address'],
    [['address' => '0x1111111111111111111111111111111111111111', 'symbol' => 'X', 'name' => 'X', 'decimals' => 'six'], 'integer "decimals"'],
]);

it('requires a symbol and a name', function (): void {
    expect(fn (): AssetRegistry => new AssetRegistry([
        'eip155:9' => ['address' => '0x1111111111111111111111111111111111111111', 'decimals' => 6, 'name' => 'X'],
    ]))->toThrow(ConfigurationException::class, 'missing "symbol"');
});

it('covers every network it names with an asset or a deliberate omission', function (): void {
    $registry = new AssetRegistry;

    foreach ($registry->networks() as $network) {
        expect(Network::normalize($network))->toBe($network);
    }

    expect($registry->all())->toHaveCount(count($registry->networks()));
});
