<?php

declare(strict_types=1);

namespace CacheShield\Lock;

interface LockProvider
{
    /** Atomically acquire an expiring lease; null means contention. Throw on backend errors. */
    public function acquire(string $key, int $ttlMilliseconds): ?Lock;
}
