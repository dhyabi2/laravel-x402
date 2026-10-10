<?php

declare(strict_types=1);

namespace Requestway\X402\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Messages\VerifyResponse;
use Throwable;

/**
 * A payment could not be collected: rejected at verification, failed at
 * settlement, or the facilitator itself failed.
 *
 * In after_response settlement mode this can fire after the client has already
 * been served, which is the trade-off that mode accepts.
 */
final class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly PaymentContext $context,
        public readonly string $stage,
        public readonly string $reason,
        public readonly string $message,
        public readonly ?VerifyResponse $verification = null,
        public readonly ?SettleResponse $settlement = null,
        public readonly ?Throwable $exception = null,
    ) {}

    public function servedWithoutPayment(): bool
    {
        return $this->stage === 'settle_after_response';
    }
}
