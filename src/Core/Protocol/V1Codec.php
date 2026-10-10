<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Protocol;

use Requestway\X402\Core\Exceptions\MalformedPaymentException;
use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequired;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Support\Base64;
use Requestway\X402\Core\Support\Json;

/**
 * Protocol v1 over HTTP, per specs/transports-v1/http.md.
 *
 * Read-only: the package accepts `X-PAYMENT` from older clients and answers
 * them on `X-PAYMENT-RESPONSE`, but it always advertises v2 requirements. A v1
 * payload is flat — `{ x402Version, scheme, network, payload }` — with no
 * `accepted` echo, so the requirement it paid for is matched server side on
 * scheme and network.
 */
final class V1Codec implements Codec
{
    public const PAYMENT_SIGNATURE = 'X-PAYMENT';

    public const PAYMENT_RESPONSE = 'X-PAYMENT-RESPONSE';

    public function version(): int
    {
        return Version::V1;
    }

    public function paymentSignatureHeader(): string
    {
        return self::PAYMENT_SIGNATURE;
    }

    public function paymentRequiredHeader(): ?string
    {
        return null;
    }

    public function paymentResponseHeader(): string
    {
        return self::PAYMENT_RESPONSE;
    }

    public function encodePaymentRequired(PaymentRequired $paymentRequired): string
    {
        return Base64::encode(Json::encode($this->paymentRequiredBody($paymentRequired)));
    }

    public function decodePaymentPayload(string $header): PaymentPayload
    {
        $data = Json::decodeObject(Base64::decode($header));

        $scheme = $data['scheme'] ?? null;
        $network = $data['network'] ?? null;
        $payload = $data['payload'] ?? null;

        if (! is_string($scheme) || $scheme === '' || ! is_string($network) || $network === '') {
            throw new MalformedPaymentException('Legacy payment payload is missing "scheme" or "network".');
        }

        if (! is_array($payload) || $payload === []) {
            throw new MalformedPaymentException('Legacy payment payload is missing "payload".');
        }

        return new PaymentPayload(
            x402Version: Version::V1,
            accepted: null,
            payload: $payload,
            legacyScheme: $scheme,
            legacyNetwork: $network,
        );
    }

    public function encodeSettlement(SettleResponse $settlement): string
    {
        return Base64::encode(Json::encode($settlement->toArray()));
    }

    public function facilitatorRequest(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): array
    {
        return [
            'x402Version' => Version::V1,
            'paymentPayload' => [
                'x402Version' => Version::V1,
                'scheme' => $payload->scheme(),
                'network' => $payload->network(),
                'payload' => $payload->payload,
            ],
            'paymentRequirements' => $requirements->toLegacyArray($resource),
        ];
    }

    public function paymentRequiredBody(PaymentRequired $paymentRequired): array
    {
        $resource = $paymentRequired->resource;

        return [
            'x402Version' => Version::V1,
            'error' => $paymentRequired->error ?? 'Payment required',
            'accepts' => array_map(
                static fn (PaymentRequirements $requirements): array => $requirements->toLegacyArray($resource),
                $paymentRequired->accepts
            ),
        ];
    }
}
