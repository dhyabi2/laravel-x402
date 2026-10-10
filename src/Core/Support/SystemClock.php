<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Support;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
