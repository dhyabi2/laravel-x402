<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Support;

final class FrozenClock implements Clock
{
    public function __construct(private int $timestamp) {}

    public function now(): int
    {
        return $this->timestamp;
    }

    public function set(int $timestamp): void
    {
        $this->timestamp = $timestamp;
    }
}
