# Executed verification

Executed on 2026-10-28 using PHP 8.3.6, PHPUnit 11.5.57 and Redis 7.0.15 in this workspace.

- `composer validate --strict`: passed.
- `composer test`: passed, 10 tests, 18 assertions.
- `REDIS_HOST=127.0.0.1 REDIS_PORT=16379 composer test:redis`: passed, 1 test, 5 assertions. Used two real Redis client connections against one local server. Checks contention, lease expiry, successor ownership, repeated release and reacquisition.
- `composer analyse`: passed, PHPStan level 8, no errors.
- `composer style`: passed after applying PSR-12 formatting.
- Database example: executed setup, then eight parallel PHP processes on a cold cache, then eight more after six seconds. Each batch returned eight correct result sets and logged exactly one database query execution. Redis backed both PSR-16 storage and leases.

Composer scripts were run with `COMPOSER_ALLOW_SUPERUSER=1` because this execution environment uses root. A first Redis attempt could not connect because a daemon did not persist across tool invocations; the successful run started Redis alongside the tests. No distributed fault-tolerance, failover, partition or multi-host test was performed. CI configuration is provided but has not been executed on GitHub. PHP 8.4 and 8.5 were not tested locally.
