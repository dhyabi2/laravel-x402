<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Facilitator\Exceptions;

use HydraLabs\X402\Core\Exceptions\X402Exception;

/**
 * The facilitator could not be used. Distinct from a facilitator that answered
 * and rejected the payment.
 */
class FacilitatorException extends X402Exception
{
    public function __construct(
        string $message,
        public readonly string $operation = '',
        public readonly ?int $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
