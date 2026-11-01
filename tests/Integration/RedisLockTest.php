<?php

declare(strict_types=1);

namespace CacheShield\Tests\Integration;

use CacheShield\Lock\RedisLockProvider;
use PHPUnit\Framework\TestCase;

final class RedisLockTest extends TestCase
{
    public function testOwnershipExpirationAndRelease(): void
    {
        if (!extension_loaded('redis') || !getenv('REDIS_HOST')) {
            self::markTestSkipped('Set REDIS_HOST and install ext-redis.');
        }
        $a = new \Redis();
        $a->connect((string) getenv('REDIS_HOST'), (int) (getenv('REDIS_PORT') ?: 6379));
        $b = new \Redis();
        $b->connect((string) getenv('REDIS_HOST'), (int) (getenv('REDIS_PORT') ?: 6379));
        $prefix = 'cache-shield-test:'.bin2hex(random_bytes(8)).':';
        $one = new RedisLockProvider($a, $prefix);
        $two = new RedisLockProvider($b, $prefix);
        try {
            $old = $one->acquire('key', 100);
            self::assertNotNull($old);
            self::assertNull($two->acquire('key', 1000));
            usleep(200000);
            $new = $two->acquire('key', 5000);
            self::assertNotNull($new);
            $old->release();
            self::assertNull($one->acquire('key', 1000), 'Old owner must not delete successor');
            $new->release();
            $new->release();
            $last = $one->acquire('key', 1000);
            self::assertNotNull($last);
            $last->release();
        } finally {
            $a->del($prefix.'key');
            $a->close();
            $b->close();
        }
    }
}
