<?php

declare(strict_types=1);

namespace HydraLabs\X402\Reporting;

/**
 * Optional outbound reporting of payment activity.
 *
 * Implementations must never block or fail a request: reporting is bookkeeping,
 * not part of the payment flow.
 */
interface Reporter
{
    /**
     * @param array<string, mixed> $event
     */
    public function record(array $event): void;

    /**
     * Send anything buffered. Called once per request, after the response has
     * been sent.
     */
    public function flush(): void;
}
