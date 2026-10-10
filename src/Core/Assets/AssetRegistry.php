<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Assets;

use Requestway\X402\Core\Exceptions\ConfigurationException;
use Requestway\X402\Core\Networks\Network;
use Requestway\X402\Core\Support\EvmAddress;

/**
 * Resolves the asset to charge in for a given network.
 *
 * Starts from the canonical default table and applies configured overrides on
 * top, so an operator can point a network at a different stablecoin — or add a
 * network the package has never heard of — without touching code.
 */
final class AssetRegistry
{
    /** @var array<string, Asset> */
    private array $assets = [];

    /**
     * @param array<string, array<string, mixed>> $overrides keyed by network identifier or alias
     */
    public function __construct(array $overrides = [], bool $withDefaults = true)
    {
        if ($withDefaults) {
            foreach (DefaultAssets::table() as $network => $definition) {
                $this->assets[$network] = Asset::fromArray($network, $definition);
            }
        }

        foreach ($overrides as $network => $definition) {
            $normalized = Network::normalize((string) $network);
            $existing = $this->assets[$normalized] ?? null;

            $this->assets[$normalized] = Asset::fromArray($normalized, $existing === null
                ? $definition
                : array_merge([
                    'address' => $existing->address,
                    'decimals' => $existing->decimals,
                    'symbol' => $existing->symbol,
                    'name' => $existing->name,
                    'version' => $existing->version,
                    'extra' => $existing->extra,
                ], $definition));
        }
    }

    public function has(string $network): bool
    {
        return isset($this->assets[Network::normalize($network)]);
    }

    /**
     * @throws ConfigurationException when no asset is configured for the network
     */
    public function for(string $network): Asset
    {
        $normalized = Network::normalize($network);

        return $this->assets[$normalized] ?? throw new ConfigurationException(sprintf(
            'No asset configured for network "%s". Add one under x402.assets.',
            $normalized
        ));
    }

    /**
     * Reverse lookup, used to label incoming payments and persist decimals.
     */
    public function find(string $network, string $address): ?Asset
    {
        $asset = $this->assets[Network::normalize($network)] ?? null;

        return $asset !== null && EvmAddress::matches($asset->address, $address) ? $asset : null;
    }

    /**
     * @return array<string, Asset>
     */
    public function all(): array
    {
        return $this->assets;
    }

    /**
     * @return list<string>
     */
    public function networks(): array
    {
        return array_keys($this->assets);
    }
}
