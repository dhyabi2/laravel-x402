<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Replay;

use HydraLabs\X402\Core\Messages\PaymentPayload;

/**
 * Resource-server-side replay protection.
 *
 * The token contract and the facilitator already stop a payment being settled
 * twice. This stops it being *delivered* twice — the duplicate-delivery
 * requirement in the `exact` scheme — by claiming each payload's fingerprint
 * before verification and holding it for the configured retention window.
 */
final class ReplayGuard
{
    public function __construct(
        private readonly ReplayStore $store,
        private readonly int $ttlSeconds,
        private readonly string $prefix = 'x402:replay:',
    ) {}

    /**
     * Claim a payload. Returns false when this exact payment has been seen.
     */
    public function claim(PaymentPayload $payload): bool
    {
        return $this->store->claim($this->key($payload), $this->ttlSeconds);
    }

    /**
     * Release a payload's claim after a failed verification or settlement:
     * nothing was charged, so the client is entitled to retry.
     */
    public function release(PaymentPayload $payload): void
    {
        $this->store->release($this->key($payload));
    }

    public function key(PaymentPayload $payload): string
    {
        return $this->prefix.$payload->fingerprint();
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }
}
