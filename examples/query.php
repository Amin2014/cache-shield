<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use CacheShield\CacheShield;
use CacheShield\Lock\RedisLockProvider;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

// Run setup once: php examples/setup.php
// Then: seq 1 8 | xargs -P8 -I{} php examples/query.php
$redis = new Redis();
$redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379));
$cache = new Psr16Cache(new RedisAdapter($redis, 'query-example'));
$shield = new CacheShield($cache, new RedisLockProvider($redis), freshTtl: 5, staleTtl: 30, waitMilliseconds: 3000);
$rows = $shield->remember('expensive-report-v1', function (): array {
    fwrite(STDERR, 'Running database query in PID '.getmypid().PHP_EOL);
    $db = new PDO('sqlite:'.__DIR__.'/example.sqlite');
    usleep(750000); // Simulate a slow report against a tiny reproducible database.
    return $db->query('SELECT category, SUM(amount) AS total FROM sales GROUP BY category')->fetchAll(PDO::FETCH_ASSOC);
});
echo json_encode($rows, JSON_THROW_ON_ERROR).PHP_EOL;
