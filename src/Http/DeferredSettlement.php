<?php

declare(strict_types=1);

namespace Requestway\X402\Http;

use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Protocol\Codec;
use Requestway\X402\Events\PaymentContext;

/**
 * Everything needed to settle a payment, carried on the request so that
 * after_response settlement can happen in terminate().
 */
final class DeferredSettlement
{
    public function __construct(
        public readonly Codec $codec,
        public readonly PaymentPayload $payload,
        public readonly PaymentRequirements $requirements,
        public readonly ResourceInfo $resource,
        public readonly PaymentContext $context,
        public readonly ?int $recordId = null,
    ) {}
}
