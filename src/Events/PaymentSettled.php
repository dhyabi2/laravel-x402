<?php

declare(strict_types=1);

namespace Requestway\X402\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Requestway\X402\Core\Messages\SettleResponse;

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
