<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Assets;

use HydraLabs\X402\Core\Exceptions\ConfigurationException;
use HydraLabs\X402\Core\Support\EvmAddress;

/**
 * A USD-pegged token on one network.
 *
 * `name` and `version` are the token contract's EIP-712 domain values. The
 * `exact`/`eip3009` scheme requires them in PaymentRequirements.extra: a client
 * cannot build a valid `transferWithAuthorization` signature without them.
 */
final class Asset
{
    /**
     * @param array<string, mixed> $extra additional scheme `extra` keys, e.g. assetTransferMethod
     */
    public function __construct(
        public readonly string $network,
        public readonly string $address,
        public readonly int $decimals,
        public readonly string $symbol,
        public readonly string $name,
        public readonly string $version,
        public readonly array $extra = [],
    ) {}

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(string $network, array $definition): self
    {
        $address = self::requireString($definition, 'address', $network);

        if (str_starts_with($network, 'eip155:') && ! EvmAddress::isValid($address)) {
            throw new ConfigurationException(sprintf(
                'Asset address "%s" for network %s is not a valid EVM address.',
                $address,
                $network
            ));
        }

        $decimals = $definition['decimals'] ?? null;

        if (! is_int($decimals) && ! (is_string($decimals) && ctype_digit($decimals))) {
            throw new ConfigurationException(sprintf('Asset "%s" is missing integer "decimals".', $network));
        }

        $extra = $definition['extra'] ?? [];

        return new self(
            network: $network,
            address: $address,
            decimals: (int) $decimals,
            symbol: self::requireString($definition, 'symbol', $network),
            name: self::requireString($definition, 'name', $network),
            version: (string) ($definition['version'] ?? '2'),
            extra: is_array($extra) ? $extra : [],
        );
    }

    /**
     * The `extra` object for PaymentRequirements. The EIP-712 domain values are
     * always present; configured extras are merged over them.
     *
     * @return array<string, mixed>
     */
    public function requirementsExtra(): array
    {
        return array_merge([
            'name' => $this->name,
            'version' => $this->version,
        ], $this->extra);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function requireString(array $definition, string $key, string $network): string
    {
        $value = $definition[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new ConfigurationException(sprintf('Asset for network %s is missing "%s".', $network, $key));
        }

        return trim($value);
    }
}
