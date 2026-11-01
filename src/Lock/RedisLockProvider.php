<?php

declare(strict_types=1);

namespace CacheShield\Lock;

final class RedisLockProvider implements LockProvider
{
    public function __construct(private readonly \Redis $redis, private readonly string $prefix = 'cache-shield-lock:')
    {
    }

    public function acquire(string $key, int $ttlMilliseconds): ?Lock
    {
        if ($ttlMilliseconds < 1) {
            throw new \InvalidArgumentException('Lock TTL must be positive.');
        }
        $key = $this->prefix.$key;
        $token = bin2hex(random_bytes(32));
        if (!$this->redis->set($key, $token, ['nx', 'px' => $ttlMilliseconds])) {
            // phpredis can return false for contention; connection errors throw RedisException.
            return null;
        }

        return new class ($this->redis, $key, $token) implements Lock {
            public function __construct(private readonly \Redis $redis, private readonly string $key, private readonly string $token)
            {
            }

            public function release(): void
            {
                $this->redis->eval(
                    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                    [$this->key, $this->token],
                    1,
                );
            }
        };
    }
}
