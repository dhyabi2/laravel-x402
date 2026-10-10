<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Support;

interface Clock
{
    /**
     * Current time as a Unix timestamp in seconds.
     */
    public function now(): int;
}
