<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Operations;

/**
 * Break-glass port for clearing a migration lock row a pre-2.0 deployment left behind.
 *
 * The 2.x migration path holds a database advisory lock, which the server drops on its own when a
 * session dies. An older binary instead claimed a row in the compatibility table, and a crash under
 * that scheme leaves the row with nobody to release it, so every later migration fails closed. This is
 * the only way to remove such a row, and it is deliberately kept out of `MigrationLock`: it is invoked
 * by hand through `database:recover-lock` once every legacy process is known to be stopped, never as a
 * step of running migrations.
 *
 * @since  2.0.0
 */
interface ExpiredMigrationLockRecovery
{
    /**
     * Remove the legacy lock row, provided it still names the expected owner and has already expired.
     *
     * The removal is a compare-and-set on the owner token, so an implementation must refuse when the
     * row has changed hands, when it is already gone, or when its expiry is still in the future — a
     * live migration must never be unlocked underneath the process running it.
     *
     * @param   string  $expectedOwnerToken  Owner token read from the stuck row, 64 lowercase hex digits.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recoverExpiredLegacyOwner(string $expectedOwnerToken): void;
}
