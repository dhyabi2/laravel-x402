<?php

declare(strict_types=1);

namespace Requestway\X402\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A protected route was requested without a usable payment, so a 402 was returned.
 */
final class PaymentRequired
{
    use Dispatchable;

    public function __construct(public readonly PaymentContext $context) {}
}
