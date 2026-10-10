<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Support;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
