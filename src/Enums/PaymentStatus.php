<?php

declare(strict_types=1);

namespace Requestway\X402\Enums;

enum PaymentStatus: string
{
    /** The facilitator accepted the payment but it has not been settled yet. */
    case Verified = 'verified';

    /** Settled on chain. */
    case Settled = 'settled';

    /** Broadcast but unconfirmed: the spec's non-terminal settlement_pending. */
    case Pending = 'pending';

    /** Rejected, or settlement failed terminally. */
    case Failed = 'failed';

    /** Observe mode: what would have been charged. */
    case Observed = 'observed';
}
