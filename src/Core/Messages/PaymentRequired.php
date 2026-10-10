<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Messages;

use HydraLabs\X402\Core\Protocol\Version;

/**
 * PaymentRequired, x402 core spec v2 §5.1.
 *
 * Carried on a 402 response in the base64 `PAYMENT-REQUIRED` header.
 */
final class PaymentRequired
{
    /**
     * @param list<PaymentRequirements> $accepts
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public readonly ResourceInfo $resource,
        public readonly array $accepts,
        public readonly ?string $error = null,
        public readonly array $extensions = [],
        public readonly int $x402Version = Version::V2,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $resource = $data['resource'] ?? [];
        $accepts = $data['accepts'] ?? [];
        $extensions = $data['extensions'] ?? [];
        $version = $data['x402Version'] ?? Version::V2;

        return new self(
            resource: ResourceInfo::fromArray(is_array($resource) ? $resource : []),
            accepts: array_values(array_map(
                static fn (array $item): PaymentRequirements => PaymentRequirements::fromArray($item),
                array_filter(is_array($accepts) ? $accepts : [], 'is_array')
            )),
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
            extensions: is_array($extensions) ? $extensions : [],
            x402Version: is_int($version) ? $version : (int) (is_numeric($version) ? $version : Version::V2),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['x402Version' => $this->x402Version];

        if ($this->error !== null) {
            $data['error'] = $this->error;
        }

        $data['resource'] = $this->resource->toArray();
        $data['accepts'] = array_map(
            static fn (PaymentRequirements $requirements): array => $requirements->toArray(),
            $this->accepts
        );

        if ($this->extensions !== []) {
            $data['extensions'] = $this->extensions;
        }

        return $data;
    }

    public function withError(?string $error): self
    {
        return new self($this->resource, $this->accepts, $error, $this->extensions, $this->x402Version);
    }

    public function first(): ?PaymentRequirements
    {
        return $this->accepts[0] ?? null;
    }
}
