<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure;

use Kumwe\CMS\Extension\Application\ExtensionRegistryLease;
use Kumwe\CMS\Infrastructure\Redis\RedisLease;

/**
 * The registry lease every extension lifecycle operation runs under: a Redis lock plus a database fence.
 *
 * The Redis lock alone cannot make the claim safe, because a holder whose lock quietly expired carries
 * on believing it still owns the registry. `RedisLockedExtensionManager` and
 * `ConsoleAdministratorThemeRecovery` therefore acquire the cross-process `extension-registry` lock,
 * draw a fence from `ExtensionRegistryFenceAllocator`, and pass the pair inward as this object.
 * Renewal is delegated to the lock and deliberately raises when the lock has already moved on, while
 * the fence is what `DoctrineExtensionManager` and `DoctrineAdministratorThemeRecovery` re-read before
 * each registry write, so a superseded operation is refused instead of overwriting newer work. Giving
 * the lock up stays with the caller that acquired it — this class only holds and renews.
 *
 * @since  2.0.0
 */
final readonly class DatabaseFencedExtensionRegistryLease implements ExtensionRegistryLease
{
    /**
     * Bind an already-acquired Redis lock to the fence allocated alongside it.
     *
     * @param  RedisLease  $mutex          Held `extension-registry` lock; the caller that acquired it
     *         is the one that releases it.
     * @param  int         $databaseFence  Fence reserved for this operation, higher than every fence
     *         issued before it.
     *
     * @since  2.0.0
     */
    public function __construct(private RedisLease $mutex, private int $databaseFence)
    {
    }

    /**
     * Report the fence reserved when this lease was taken.
     *
     * @return  int  The reserved value, fixed for the lease's lifetime; a registry row whose stored
     *          fence has moved past it belongs to a newer operation.
     *
     * @since   2.0.0
     */
    public function fence(): int
    {
        return $this->databaseFence;
    }

    /**
     * Push the Redis lock's expiry out so the operation keeps its exclusivity.
     *
     * Only the lock is re-armed; the fence is not reallocated, so the number the registry checks
     * against stays the same across an operation. A lock that expired or was re-taken elsewhere is
     * reported rather than ignored, which stops the operation before it writes under a claim it has
     * already lost.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the lock had expired or is now held by a newer operation.
     *
     * @since   2.0.0
     */
    public function renew(): void
    {
        $this->mutex->renew();
    }
}
