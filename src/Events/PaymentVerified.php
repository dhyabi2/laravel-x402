<?php

declare(strict_types=1);

namespace Requestway\X402\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A facilitator confirmed a payment authorization is valid. Nothing has been charged yet.
 */
final class PaymentVerified
{
    use Dispatchable;

    public function __construct(public readonly PaymentContext $context) {}
}
