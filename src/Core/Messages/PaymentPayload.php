<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Messages;

use HydraLabs\X402\Core\Exceptions\MalformedPaymentException;
use HydraLabs\X402\Core\Money\Integers;
use HydraLabs\X402\Core\Protocol\Version;
use HydraLabs\X402\Core\Support\Json;

/**
 * PaymentPayload, x402 core spec v2 §5.2.
 *
 * Sent by the client in the base64 `PAYMENT-SIGNATURE` header (v2) or
 * `X-PAYMENT` header (v1). A v1 payload is flat and carries no `accepted`
 * object, so `scheme` and `network` fall back to its top-level fields.
 */
final class PaymentPayload
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public readonly int $x402Version,
        public readonly ?PaymentRequirements $accepted,
        public readonly array $payload,
        public readonly ?ResourceInfo $resource = null,
        public readonly array $extensions = [],
        public readonly ?string $legacyScheme = null,
        public readonly ?string $legacyNetwork = null,
    ) {}

    /**
     * Decode the v2 wire shape.
     *
     * @param array<string, mixed> $data
     *
     * @throws MalformedPaymentException
     */
    public static function fromArray(array $data): self
    {
        $accepted = $data['accepted'] ?? null;

        if (! is_array($accepted)) {
            throw new MalformedPaymentException('Payment payload is missing "accepted".');
        }

        $payload = $data['payload'] ?? null;

        if (! is_array($payload) || $payload === []) {
            throw new MalformedPaymentException('Payment payload is missing "payload".');
        }

        $resource = $data['resource'] ?? null;
        $extensions = $data['extensions'] ?? [];

        return new self(
            x402Version: Version::require($data['x402Version'] ?? null),
            accepted: PaymentRequirements::fromArray($accepted),
            payload: $payload,
            resource: is_array($resource) && isset($resource['url']) ? ResourceInfo::fromArray($resource) : null,
            extensions: is_array($extensions) ? $extensions : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->x402Version === Version::V1) {
            return [
                'x402Version' => Version::V1,
                'scheme' => $this->scheme(),
                'network' => $this->network(),
                'payload' => $this->payload,
            ];
        }

        $data = ['x402Version' => $this->x402Version];

        if ($this->resource !== null) {
            $data['resource'] = $this->resource->toArray();
        }

        if ($this->accepted !== null) {
            $data['accepted'] = $this->accepted->toArray();
        }

        $data['payload'] = $this->payload;

        if ($this->extensions !== []) {
            $data['extensions'] = $this->extensions;
        }

        return $data;
    }

    public function scheme(): string
    {
        return $this->accepted !== null ? $this->accepted->scheme : ($this->legacyScheme ?? '');
    }

    public function network(): string
    {
        return $this->accepted !== null ? $this->accepted->network : ($this->legacyNetwork ?? '');
    }

    public function resourceUrl(): ?string
    {
        return $this->resource?->url;
    }

    /**
     * Replace `accepted` with the server-side requirement a v1 payload matched,
     * so downstream code has a single shape to work with.
     */
    public function withAccepted(PaymentRequirements $accepted): self
    {
        return new self(
            x402Version: $this->x402Version,
            accepted: $accepted,
            payload: $this->payload,
            resource: $this->resource,
            extensions: $this->extensions,
            legacyScheme: $this->legacyScheme,
            legacyNetwork: $this->legacyNetwork,
        );
    }

    /**
     * The EIP-3009 authorization object, when the payload carries one.
     *
     * @return array<string, mixed>|null
     */
    public function authorization(): ?array
    {
        $authorization = $this->payload['authorization'] ?? $this->payload['permit2Authorization'] ?? null;

        return is_array($authorization) ? $authorization : null;
    }

    public function payerAddress(): ?string
    {
        $from = $this->authorization()['from'] ?? null;

        return is_string($from) ? $from : null;
    }

    /**
     * The authorized value in atomic units, when the payload carries one.
     */
    public function authorizedValue(): ?string
    {
        $authorization = $this->authorization();
        $value = $authorization['value'] ?? $authorization['permitted']['amount'] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && Integers::isValid($value) ? Integers::normalize($value) : null;
    }

    public function recipientAddress(): ?string
    {
        $authorization = $this->authorization();
        $to = $authorization['to'] ?? $authorization['witness']['to'] ?? null;

        return is_string($to) ? $to : null;
    }

    public function validAfter(): ?int
    {
        return self::timestamp($this->authorization()['validAfter'] ?? $this->authorization()['witness']['validAfter'] ?? null);
    }

    public function validBefore(): ?int
    {
        return self::timestamp($this->authorization()['validBefore'] ?? $this->authorization()['deadline'] ?? null);
    }

    public function nonce(): ?string
    {
        $nonce = $this->authorization()['nonce'] ?? null;

        return is_string($nonce) || is_int($nonce) ? (string) $nonce : null;
    }

    /**
     * A stable fingerprint of the signed payment, used for replay protection.
     *
     * Hashes the whole scheme payload rather than just the nonce, so it works
     * for any scheme: two payloads with the same fingerprint authorize the same
     * transfer, whatever the scheme calls its replay primitive.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            strtolower($this->network()),
            strtolower($this->scheme()),
            strtolower($this->accepted !== null ? $this->accepted->asset : ''),
            Json::canonical($this->payload),
        ]));
    }

    private static function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
