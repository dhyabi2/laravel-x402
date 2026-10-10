<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Replay;

/**
 * Atomic single-use claims over payment fingerprints.
 *
 * The contract is deliberately narrow so it can be backed by anything with a
 * compare-and-set: a Laravel cache store, Redis, or a database unique index.
 */
interface ReplayStore
{
    /**
     * Claim a key. Must be atomic: of two concurrent calls with the same key,
     * exactly one returns true.
     */
    public function claim(string $key, int $ttlSeconds): bool;

    /**
     * Release a claim, so a client may retry a payment that was never charged.
     */
    public function release(string $key): void;
}
