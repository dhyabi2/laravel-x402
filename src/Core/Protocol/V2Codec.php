<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Protocol;

use HydraLabs\X402\Core\Messages\PaymentPayload;
use HydraLabs\X402\Core\Messages\PaymentRequired;
use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Core\Messages\ResourceInfo;
use HydraLabs\X402\Core\Messages\SettleResponse;
use HydraLabs\X402\Core\Support\Base64;
use HydraLabs\X402\Core\Support\Json;

/**
 * Protocol v2 over HTTP, per specs/transports-v2/http.md.
 *
 * Everything protocol-level travels in base64 JSON headers. The body is a
 * server concern; this codec emits the same PaymentRequired object as JSON so
 * that a browser or a curl user sees something readable.
 */
final class V2Codec implements Codec
{
    public const PAYMENT_REQUIRED = 'PAYMENT-REQUIRED';

    public const PAYMENT_SIGNATURE = 'PAYMENT-SIGNATURE';

    public const PAYMENT_RESPONSE = 'PAYMENT-RESPONSE';

    public function version(): int
    {
        return Version::V2;
    }

    public function paymentSignatureHeader(): string
    {
        return self::PAYMENT_SIGNATURE;
    }

    public function paymentRequiredHeader(): string
    {
        return self::PAYMENT_REQUIRED;
    }

    public function paymentResponseHeader(): string
    {
        return self::PAYMENT_RESPONSE;
    }

    public function encodePaymentRequired(PaymentRequired $paymentRequired): string
    {
        return Base64::encode(Json::encode($paymentRequired->toArray()));
    }

    public function decodePaymentPayload(string $header): PaymentPayload
    {
        return PaymentPayload::fromArray(Json::decodeObject(Base64::decode($header)));
    }

    public function encodeSettlement(SettleResponse $settlement): string
    {
        return Base64::encode(Json::encode($settlement->toArray()));
    }

    public function facilitatorRequest(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): array
    {
        return [
            'x402Version' => $payload->x402Version,
            'paymentPayload' => $payload->toArray(),
            'paymentRequirements' => $requirements->toArray(),
        ];
    }

    public function paymentRequiredBody(PaymentRequired $paymentRequired): array
    {
        return $paymentRequired->toArray();
    }
}
