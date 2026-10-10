<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Facilitator;

use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorException;
use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Messages\SupportedResponse;
use Requestway\X402\Core\Messages\VerifyResponse;

/**
 * A facilitator verifies and settles payments on chain on the resource
 * server's behalf, per x402 core spec v2 §7.
 *
 * Implementations must never throw for a payment the facilitator rejected —
 * that is an ordinary `isValid: false` / `success: false` response. Exceptions
 * are reserved for the facilitator itself being unreachable or broken.
 */
interface Facilitator
{
    /**
     * Read-only validation. Must not commit payment state.
     *
     * @throws FacilitatorException
     */
    public function verify(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): VerifyResponse;

    /**
     * Durably commit the payment.
     *
     * @throws FacilitatorException
     */
    public function settle(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): SettleResponse;

    /**
     * Schemes, networks and extensions this facilitator can handle.
     *
     * @throws FacilitatorException
     */
    public function supported(): SupportedResponse;
}
