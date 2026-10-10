<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Messages;

use HydraLabs\X402\Core\Exceptions\MalformedPaymentException;
use HydraLabs\X402\Core\Money\Integers;
use HydraLabs\X402\Core\Support\EvmAddress;

/**
 * PaymentRequirements, x402 core spec v2 §5.1.2.
 *
 * One acceptable way to pay for a resource. `amount` is always a decimal string
 * in atomic units of `asset`.
 */
final class PaymentRequirements
{
    public const SCHEME_EXACT = 'exact';

    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $scheme,
        public readonly string $network,
        public readonly string $amount,
        public readonly string $asset,
        public readonly string $payTo,
        public readonly int $maxTimeoutSeconds,
        public readonly array $extra = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     *
     * @throws MalformedPaymentException
     */
    public static function fromArray(array $data): self
    {
        $extra = $data['extra'] ?? [];
        $amount = $data['amount'] ?? $data['maxAmountRequired'] ?? null;

        if (! is_string($amount) && ! is_int($amount)) {
            throw new MalformedPaymentException('Payment requirements are missing "amount".');
        }

        $amount = (string) $amount;

        if (! Integers::isValid($amount)) {
            throw new MalformedPaymentException(sprintf('Payment amount "%s" is not an atomic-unit integer.', $amount));
        }

        return new self(
            scheme: self::requireString($data, 'scheme'),
            network: self::requireString($data, 'network'),
            amount: Integers::normalize($amount),
            asset: self::requireString($data, 'asset'),
            payTo: self::requireString($data, 'payTo'),
            maxTimeoutSeconds: self::requireTimeout($data),
            extra: is_array($extra) ? $extra : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'scheme' => $this->scheme,
            'network' => $this->network,
            'amount' => $this->amount,
            'asset' => $this->asset,
            'payTo' => $this->payTo,
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
        ];

        if ($this->extra !== []) {
            $data['extra'] = $this->extra;
        }

        return $data;
    }

    /**
     * Protocol v1 wire shape, used only when talking to a facilitator about a
     * v1 payload. v1 carried the resource metadata inside each requirement.
     *
     * @return array<string, mixed>
     */
    public function toLegacyArray(ResourceInfo $resource): array
    {
        return [
            'scheme' => $this->scheme,
            'network' => $this->network,
            'maxAmountRequired' => $this->amount,
            'resource' => $resource->url,
            'description' => $resource->description ?? '',
            'mimeType' => $resource->mimeType ?? '',
            'outputSchema' => null,
            'payTo' => $this->payTo,
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
            'asset' => $this->asset,
            'extra' => $this->extra === [] ? null : $this->extra,
        ];
    }

    /**
     * Whether a client's echoed requirement satisfies this advertised one.
     *
     * Follows the reference server's `paymentRequirementsMatchAccepted`:
     * every core field must be reproduced and every server-declared `extra`
     * key must be present and equal, while the client may add keys of its own.
     *
     * Two deliberate relaxations, both documented in docs/SPEC_NOTES.md:
     * addresses compare case-insensitively, and an amount at or above the
     * required amount is accepted — never below it.
     */
    public function isSatisfiedBy(self $accepted): bool
    {
        if ($this->scheme !== $accepted->scheme || $this->network !== $accepted->network) {
            return false;
        }

        if (! EvmAddress::matches($this->asset, $accepted->asset)) {
            return false;
        }

        if (! EvmAddress::matches($this->payTo, $accepted->payTo)) {
            return false;
        }

        if ($this->maxTimeoutSeconds !== $accepted->maxTimeoutSeconds) {
            return false;
        }

        if (! Integers::greaterThanOrEqual($accepted->amount, $this->amount)) {
            return false;
        }

        return self::containsSubset($this->extra, $accepted->extra);
    }

    public function isUnderpaidBy(self $accepted): bool
    {
        return ! Integers::greaterThanOrEqual($accepted->amount, $this->amount);
    }

    public function assetTransferMethod(): string
    {
        $method = $this->extra['assetTransferMethod'] ?? null;

        return is_string($method) && $method !== '' ? $method : 'eip3009';
    }

    public function paymentFlow(): string
    {
        $flow = $this->extra['paymentFlow'] ?? null;

        return is_string($flow) && $flow !== '' ? $flow : 'authorization';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new MalformedPaymentException(sprintf('Payment requirements are missing "%s".', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireTimeout(array $data): int
    {
        $value = $data['maxTimeoutSeconds'] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new MalformedPaymentException('Payment requirements are missing "maxTimeoutSeconds".');
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    private static function containsSubset(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }

            $candidate = $actual[$key];

            if (is_array($value)) {
                if (! is_array($candidate) || ! self::containsSubset($value, $candidate)) {
                    return false;
                }

                continue;
            }

            if ($value !== $candidate) {
                return false;
            }
        }

        return true;
    }
}
