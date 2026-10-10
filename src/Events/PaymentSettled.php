<?php

declare(strict_types=1);

namespace HydraLabs\X402\Events;

use HydraLabs\X402\Core\Messages\SettleResponse;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment was settled. Funds have moved to the configured wallet.
 */
final class PaymentSettled
{
    use Dispatchable;

    public function __construct(
        public readonly PaymentContext $context,
        public readonly SettleResponse $settlement,
    ) {}

    public function transaction(): string
    {
        return $this->settlement->transaction;
    }
}
