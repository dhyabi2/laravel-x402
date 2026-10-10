<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Testing;

use HydraLabs\X402\Core\Messages\PaymentPayload;
use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Core\Messages\ResourceInfo;
use HydraLabs\X402\Core\Protocol\Version;
use HydraLabs\X402\Core\Support\Clock;
use HydraLabs\X402\Core\Support\SystemClock;

/**
 * Builds structurally valid `exact`/EIP-3009 payloads without a wallet.
 *
 * The signature is not real, so these only pass a facilitator that does not
 * check signatures — the FakeFacilitator. Used by `php artisan x402:test` and
 * by the package's own test suite to drive the full 402 → pay → 200 exchange.
 */
final class PaymentPayloadFactory
{
    public const DEFAULT_PAYER = '0x857b06519E91e3A54538791bDbb0E22373e36b66';

    public function __construct(private readonly Clock $clock = new SystemClock) {}

    /**
     * @param array<string, mixed> $overrides merged into the authorization object
     */
    public function exact(
        PaymentRequirements $requirements,
        ?ResourceInfo $resource = null,
        string $payer = self::DEFAULT_PAYER,
        ?string $amount = null,
        array $overrides = [],
        int $version = Version::V2,
    ): PaymentPayload {
        $now = $this->clock->now();

        $authorization = array_merge([
            'from' => $payer,
            'to' => $requirements->payTo,
            'value' => $amount ?? $requirements->amount,
            'validAfter' => (string) ($now - 60),
            'validBefore' => (string) ($now + max(1, $requirements->maxTimeoutSeconds)),
            'nonce' => $this->nonce(),
        ], $overrides);

        return new PaymentPayload(
            x402Version: $version,
            accepted: $version === Version::V1 ? null : $requirements,
            payload: [
                'signature' => $this->signature(),
                'authorization' => $authorization,
            ],
            resource: $version === Version::V1 ? null : $resource,
            legacyScheme: $version === Version::V1 ? $requirements->scheme : null,
            legacyNetwork: $version === Version::V1 ? $requirements->network : null,
        );
    }

    public function nonce(): string
    {
        return '0x'.bin2hex(random_bytes(32));
    }

    /**
     * A 65-byte hex string, the right shape for an EIP-712 signature.
     */
    public function signature(): string
    {
        return '0x'.bin2hex(random_bytes(65));
    }
}
