<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Messages;

use HydraLabs\X402\Core\Networks\Network;
use HydraLabs\X402\Core\Protocol\Version;

final class SupportedKind
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly int $x402Version,
        public readonly string $scheme,
        public readonly string $network,
        public readonly array $extra = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $version = $data['x402Version'] ?? Version::V2;
        $extra = $data['extra'] ?? [];

        return new self(
            x402Version: is_numeric($version) ? (int) $version : Version::V2,
            scheme: is_string($data['scheme'] ?? null) ? $data['scheme'] : '',
            network: is_string($data['network'] ?? null) ? $data['network'] : '',
            extra: is_array($extra) ? $extra : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'x402Version' => $this->x402Version,
            'scheme' => $this->scheme,
            'network' => $this->network,
        ];

        if ($this->extra !== []) {
            $data['extra'] = $this->extra;
        }

        return $data;
    }

    public function matches(string $scheme, string $network, int $version): bool
    {
        if ($this->x402Version !== $version || $this->scheme !== $scheme) {
            return false;
        }

        if ($this->network === $network) {
            return true;
        }

        return str_ends_with($this->network, ':*')
            && Network::namespace($this->network) === Network::namespace($network);
    }
}
