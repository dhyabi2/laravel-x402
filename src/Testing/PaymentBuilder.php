<?php

declare(strict_types=1);

namespace Requestway\X402\Testing;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequired;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Protocol\V1Codec;
use Requestway\X402\Core\Protocol\V2Codec;
use Requestway\X402\Core\Protocol\Version;
use Requestway\X402\Core\Support\Base64;
use Requestway\X402\Core\Support\Json;
use Requestway\X402\Core\Testing\PaymentPayloadFactory;

/**
 * Builds payments for your own paid routes in tests, and decodes the headers
 * they come back with.
 *
 * Payloads are structurally valid but not really signed, so they only work
 * against the fake facilitator installed by X402::fake().
 *
 * Usually reached through the MakesX402Payments trait rather than directly.
 */
final class PaymentBuilder
{
    public const DEFAULT_PAYER = PaymentPayloadFactory::DEFAULT_PAYER;

    public function __construct(private readonly PaymentPayloadFactory $payloads = new PaymentPayloadFactory) {}

    /**
     * The headers a paying client would send in response to a 402.
     *
     * @param TestResponse<\Illuminate\Http\Response>|PaymentRequired|PaymentRequirements $from
     * @param array<string, mixed> $overrides merged into the EIP-3009 authorization
     * @return array<string, string>
     */
    public function headers(
        TestResponse|PaymentRequired|PaymentRequirements $from,
        ?string $amount = null,
        array $overrides = [],
        string $payer = self::DEFAULT_PAYER,
        int $version = Version::V2,
    ): array {
        $header = $version === Version::V1
            ? V1Codec::PAYMENT_SIGNATURE
            : V2Codec::PAYMENT_SIGNATURE;

        return [$header => Base64::encode(Json::encode(
            $this->payload($from, $amount, $overrides, $payer, $version)->toArray()
        ))];
    }

    /**
     * The payload behind headers(), for assertions on its contents.
     *
     * @param TestResponse<\Illuminate\Http\Response>|PaymentRequired|PaymentRequirements $from
     * @param array<string, mixed> $overrides
     */
    public function payload(
        TestResponse|PaymentRequired|PaymentRequirements $from,
        ?string $amount = null,
        array $overrides = [],
        string $payer = self::DEFAULT_PAYER,
        int $version = Version::V2,
    ): PaymentPayload {
        [$requirements, $resource] = $this->terms($from);

        return $this->payloads->exact(
            requirements: $requirements,
            resource: $resource,
            payer: $payer,
            amount: $amount,
            overrides: $overrides,
            version: $version,
        );
    }

    /**
     * Decode the PAYMENT-REQUIRED header from a 402 response.
     *
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    public function requirements(TestResponse $response): PaymentRequired
    {
        return PaymentRequired::fromArray(Json::decodeObject(Base64::decode(
            $this->header($response, V2Codec::PAYMENT_REQUIRED)
        )));
    }

    /**
     * Decode the PAYMENT-RESPONSE header from a settled response.
     *
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    public function settlement(TestResponse $response): SettleResponse
    {
        return SettleResponse::fromArray(Json::decodeObject(Base64::decode(
            $this->header($response, V2Codec::PAYMENT_RESPONSE, V1Codec::PAYMENT_RESPONSE)
        )));
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function header(TestResponse $response, string $name, ?string $fallback = null): string
    {
        $value = $response->headers->get($name);

        if (! is_string($value) && $fallback !== null) {
            $value = $response->headers->get($fallback);
        }

        Assert::assertIsString($value, sprintf(
            'Expected a %s header on the response, but there was none. Status was %d.',
            $name,
            $response->getStatusCode()
        ));

        return $value;
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response>|PaymentRequired|PaymentRequirements $from
     * @return array{0: PaymentRequirements, 1: ResourceInfo|null}
     */
    private function terms(TestResponse|PaymentRequired|PaymentRequirements $from): array
    {
        if ($from instanceof PaymentRequirements) {
            return [$from, null];
        }

        $required = $from instanceof PaymentRequired ? $from : $this->requirements($from);
        $requirements = $required->first();

        Assert::assertNotNull($requirements, 'The 402 response advertised no payment methods.');

        return [$requirements, $required->resource];
    }
}
