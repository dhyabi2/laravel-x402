<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Protocol;

use HydraLabs\X402\Core\Exceptions\MalformedPaymentException;
use HydraLabs\X402\Core\Messages\PaymentPayload;
use HydraLabs\X402\Core\Messages\PaymentRequired;
use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Core\Messages\ResourceInfo;
use HydraLabs\X402\Core\Messages\SettleResponse;

/**
 * All wire-format knowledge for one protocol version.
 *
 * Header names, base64/JSON framing and facilitator request bodies all changed
 * between v1 and v2 and will change again. A new protocol version is a new
 * implementation of this interface, not a rewrite of the middleware.
 */
interface Codec
{
    public function version(): int;

    /**
     * The request header a client sends its payment payload in.
     */
    public function paymentSignatureHeader(): string;

    /**
     * The response header the 402 payment requirements go out on, if any.
     * Protocol v1 carried them in the body instead.
     */
    public function paymentRequiredHeader(): ?string;

    /**
     * The response header the settlement result goes out on.
     */
    public function paymentResponseHeader(): string;

    public function encodePaymentRequired(PaymentRequired $paymentRequired): string;

    /**
     * @throws MalformedPaymentException
     */
    public function decodePaymentPayload(string $header): PaymentPayload;

    public function encodeSettlement(SettleResponse $settlement): string;

    /**
     * The body of a facilitator `/verify` or `/settle` call for this version.
     *
     * @return array<string, mixed>
     */
    public function facilitatorRequest(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): array;

    /**
     * The 402 response body for this version.
     *
     * @return array<string, mixed>
     */
    public function paymentRequiredBody(PaymentRequired $paymentRequired): array;
}
