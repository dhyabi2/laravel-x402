<?php

declare(strict_types=1);

namespace HydraLabs\X402\Replay;

use HydraLabs\X402\Core\Replay\ReplayStore;
use Illuminate\Contracts\Cache\Repository;

/**
 * Replay claims backed by a Laravel cache store.
 *
 * Uses add(), which is atomic on every store that can be shared between web
 * nodes (Redis, Memcached, DynamoDB, database). The "array" and "file" stores
 * are not shared, so x402:doctor warns when one of them is configured.
 */
final class CacheReplayStore implements ReplayStore
{
    public function __construct(private readonly Repository $cache) {}

    public function claim(string $key, int $ttlSeconds): bool
    {
        return $this->cache->add($key, true, $ttlSeconds);
    }

    public function release(string $key): void
    {
        $this->cache->forget($key);
    }
}
