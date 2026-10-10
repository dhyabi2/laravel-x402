<?php

declare(strict_types=1);

namespace HydraLabs\X402\Testing;

use HydraLabs\X402\Core\Messages\PaymentPayload;
use HydraLabs\X402\Core\Messages\PaymentRequired;
use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Core\Messages\SettleResponse;
use HydraLabs\X402\Core\Protocol\Version;
use Illuminate\Testing\TestResponse;

/**
 * Pay your own paid routes in tests. Delegates to PaymentBuilder.
 *
 *     uses(MakesX402Payments::class);
 *
 *     X402::fake();
 *
 *     $required = $this->get('/report');
 *     $this->get('/report', $this->x402Payment($required))->assertOk();
 */
trait MakesX402Payments
{
    public const TEST_PAYER = PaymentBuilder::DEFAULT_PAYER;

    /**
     * @param TestResponse<\Illuminate\Http\Response>|PaymentRequired|PaymentRequirements $from
     * @param array<string, mixed> $overrides
     * @return array<string, string>
     */
    public function x402Payment(
        TestResponse|PaymentRequired|PaymentRequirements $from,
        ?string $amount = null,
        array $overrides = [],
        string $payer = self::TEST_PAYER,
        int $version = Version::V2,
    ): array {
        return $this->x402Builder()->headers($from, $amount, $overrides, $payer, $version);
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response>|PaymentRequired|PaymentRequirements $from
     * @param array<string, mixed> $overrides
     */
    public function x402Payload(
        TestResponse|PaymentRequired|PaymentRequirements $from,
        ?string $amount = null,
        array $overrides = [],
        string $payer = self::TEST_PAYER,
        int $version = Version::V2,
    ): PaymentPayload {
        return $this->x402Builder()->payload($from, $amount, $overrides, $payer, $version);
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    public function x402Requirements(TestResponse $response): PaymentRequired
    {
        return $this->x402Builder()->requirements($response);
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    public function x402Settlement(TestResponse $response): SettleResponse
    {
        return $this->x402Builder()->settlement($response);
    }

    public function x402Builder(): PaymentBuilder
    {
        return new PaymentBuilder;
    }
}
