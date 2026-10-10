<?php

declare(strict_types=1);

namespace HydraLabs\X402\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Observe mode: what would have been charged, had the package been enforcing.
 */
final class PaymentObserved
{
    use Dispatchable;

    public function __construct(public readonly PaymentContext $context) {}
}
