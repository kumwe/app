<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

/**
 * Cluster-wide mutual exclusion around a migration run.
 *
 * Every replica boots the same `MigrationPlan`, so without a lock several would apply the same DDL at
 * once and race each other into the ledger. An implementation holds exclusion for the whole callback —
 * the migrations and their ledger writes together — and releases it however the callback ends.
 * `DoctrineMigrationLock` is the shipped implementation; it takes a database advisory lock without
 * waiting, so a replica that loses the race fails fast rather than blocking on startup.
 *
 * @since  2.0.0
 */
interface MigrationLock
{
    /**
     * Runs the operation while holding the migration lock.
     *
     * The lock is released on the return path and on the exception path alike, so a migration that
     * fails part way through does not leave the next deployment locked out.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Migration work to run under exclusion.
     *
     * @return  T  Whatever the operation returned, handed back untouched.
     *
     * @since   2.0.0
     */
    public function synchronized(callable $operation): mixed;
}
