<?php

declare(strict_types=1);

namespace CacheShield\Tests\Unit;

use CacheShield\CacheShield;
use CacheShield\CacheWriteException;
use CacheShield\Lock\Lock;
use CacheShield\Lock\LockProvider;
use CacheShield\LockTimeoutException;
use PHPUnit\Framework\TestCase;

final class FakeLocks implements LockProvider
{
    public bool $held = false;
    public int $released = 0;
    public ?\Closure $onAcquire = null;
    public bool $failRelease = false;

    public function acquire(string $key, int $ttlMilliseconds): ?Lock
    {
        if ($this->onAcquire !== null) {
            ($this->onAcquire)();
        }
        if ($this->held) {
            return null;
        }
        $this->held = true;
        return new class ($this) implements Lock {
            public function __construct(private FakeLocks $owner)
            {
            }
            public function release(): void
            {
                $this->owner->held = false;
                ++$this->owner->released;
                if ($this->owner->failRelease) {
                    throw new \RuntimeException('release');
                }
            }
        };
    }
}

final class CacheShieldTest extends TestCase
{
    private FakeCache $cache;
    private FakeLocks $locks;
    private string $key;

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
        $this->locks = new FakeLocks();
        $this->key = hash('sha256', "default\0query");
    }

    private function seed(float $fresh, float $until, mixed $value = 'old'): void
    {
        $this->cache->set($this->key, ['version' => 1, 'value' => $value, 'fresh' => microtime(true) + $fresh, 'until' => microtime(true) + $until], 60);
    }

    public function testMissAndFreshNullAreCached(): void
    {
        $shield = new CacheShield($this->cache, $this->locks);
        self::assertNull($shield->remember('query', fn () => null));
        self::assertNull($shield->remember('query', fn () => self::fail('fresh loader called')));
        self::assertSame(1, $this->locks->released);
    }

    public function testFalseIsCached(): void
    {
        $shield = new CacheShield($this->cache, $this->locks);
        self::assertFalse($shield->remember('query', fn () => false));
        self::assertFalse($shield->remember('query', fn () => true));
    }

    public function testContendingCallerServesStaleDuringRefresh(): void
    {
        $this->seed(-1, 30);
        $shield = new CacheShield($this->cache, $this->locks);
        self::assertSame('new', $shield->remember('query', function () use ($shield) {
            self::assertSame('old', $shield->remember('query', fn () => self::fail('duplicate loader')));
            return 'new';
        }));
        self::assertSame('new', $shield->remember('query', fn () => self::fail('fresh')));
    }

    public function testHardExpiredValueIsNeverServed(): void
    {
        $this->seed(-20, -1);
        $this->locks->held = true;
        $this->expectException(LockTimeoutException::class);
        (new CacheShield($this->cache, $this->locks, waitMilliseconds: 0))->remember('query', fn () => self::fail());
    }

    public function testColdMissWaitIsBounded(): void
    {
        $this->locks->held = true;
        $start = hrtime(true);
        try {
            (new CacheShield($this->cache, $this->locks, waitMilliseconds: 20))->remember('query', fn () => self::fail());
            self::fail('timeout missing');
        } catch (LockTimeoutException) {
            self::assertLessThan(500, (hrtime(true) - $start) / 1e6);
        }
    }

    public function testRechecksAfterAcquisition(): void
    {
        $this->locks->onAcquire = fn () => $this->seed(20, 40, 'published');
        self::assertSame('published', (new CacheShield($this->cache, $this->locks))->remember('query', fn () => self::fail()));
        self::assertSame(1, $this->locks->released);
    }

    public function testFailurePreservesStaleAndReleases(): void
    {
        $this->seed(-1, 30);
        $this->locks->failRelease = true;
        try {
            (new CacheShield($this->cache, $this->locks))->remember('query', fn () => throw new \LogicException('loader'));
            self::fail();
        } catch (\LogicException $e) {
            self::assertSame('loader', $e->getMessage());
        }
        self::assertFalse($this->locks->held);
        self::assertSame('old', $this->cache->get($this->key)['value']);
    }

    public function testCacheWriteFailureReleases(): void
    {
        $cache = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willReturn(false);
        try {
            (new CacheShield($cache, $this->locks))->remember('query', fn () => 1);
            self::fail();
        } catch (CacheWriteException) {
            self::assertSame(1, $this->locks->released);
        }
    }

    public function testMalformedEntryIsAMiss(): void
    {
        $this->cache->set($this->key, ['version' => 1]);
        self::assertSame(42, (new CacheShield($this->cache, $this->locks))->remember('query', fn () => 42));
    }

    public function testInvalidConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheShield($this->cache, $this->locks, staleTtl: -1);
    }
}
