<?php

declare(strict_types=1);

namespace CacheShield\Lock;

interface Lock
{
    /** Atomically release only this lease's ownership token. Safe after expiration. */
    public function release(): void;
}
