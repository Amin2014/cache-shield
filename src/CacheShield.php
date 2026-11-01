<?php

declare(strict_types=1);

namespace CacheShield;

use CacheShield\Lock\LockProvider;
use Psr\SimpleCache\CacheInterface;

final class CacheShield
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LockProvider $locks,
        private readonly int $freshTtl = 60,
        private readonly int $staleTtl = 300,
        private readonly int $lockTtlMilliseconds = 30000,
        private readonly int $waitMilliseconds = 1000,
        private readonly int $pollMilliseconds = 10,
        private readonly string $namespace = 'default',
    ) {
        if ($freshTtl < 1 || $staleTtl < 0 || $freshTtl > PHP_INT_MAX - $staleTtl
            || $lockTtlMilliseconds < 1 || $waitMilliseconds < 0 || $pollMilliseconds < 1) {
            throw new \InvalidArgumentException('Invalid TTL or wait configuration.');
        }
    }

    /**
     * Null and false are cacheable. Loader exceptions propagate; stale fallback is contention-only.
     * @param callable(): mixed $loader
     */
    public function remember(string $key, callable $loader): mixed
    {
        $key = hash('sha256', $this->namespace."\0".$key);
        $deadline = hrtime(true) / 1e6 + $this->waitMilliseconds;
        do {
            $entry = $this->read($key);
            if ($entry !== null && microtime(true) < $entry['fresh']) {
                return $entry['value'];
            }
            $lock = $this->locks->acquire($key, $this->lockTtlMilliseconds);
            if ($lock !== null) {
                $primary = null;
                try {
                    // Another owner may have published between the first read and acquisition.
                    $entry = $this->read($key);
                    if ($entry !== null && microtime(true) < $entry['fresh']) {
                        return $entry['value'];
                    }
                    $value = $loader();
                    $now = microtime(true);
                    if (!$this->cache->set($key, [
                        'version' => 1, 'value' => $value,
                        'fresh' => $now + $this->freshTtl,
                        'until' => $now + $this->freshTtl + $this->staleTtl,
                    ], $this->freshTtl + $this->staleTtl)) {
                        throw new CacheWriteException('Cache rejected the refreshed value.');
                    }
                    return $value;
                } catch (\Throwable $error) {
                    $primary = $error;
                    throw $error;
                } finally {
                    try {
                        $lock->release();
                    } catch (\Throwable $releaseError) {
                        // Preserve a loader/storage exception if cleanup also fails.
                        if ($primary === null) {
                            throw $releaseError;
                        }
                    }
                }
            }
            if ($entry !== null && microtime(true) < $entry['until']) {
                return $entry['value'];
            }
            $remaining = $deadline - hrtime(true) / 1e6;
            if ($remaining <= 0) {
                throw new LockTimeoutException('No usable value before the lock wait deadline.');
            }
            usleep((int) min($remaining * 1000, $this->pollMilliseconds * 1000));
        } while (true);
    }

    /** @return array{version: int, value: mixed, fresh: float, until: float}|null */
    private function read(string $key): ?array
    {
        $entry = $this->cache->get($key);
        if (!is_array($entry) || ($entry['version'] ?? null) !== 1
            || !array_key_exists('value', $entry)
            || !is_float($entry['fresh'] ?? null) || !is_float($entry['until'] ?? null)
            || !is_finite($entry['fresh']) || !is_finite($entry['until'])
            || $entry['fresh'] > $entry['until'] || microtime(true) >= $entry['until']) {
            return null;
        }
        return ['version' => 1, 'value' => $entry['value'], 'fresh' => $entry['fresh'], 'until' => $entry['until']];
    }
}
