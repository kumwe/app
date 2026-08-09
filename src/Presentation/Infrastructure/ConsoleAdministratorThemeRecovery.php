<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Infrastructure;

use Kumwe\CMS\Extension\Infrastructure\DatabaseFencedExtensionRegistryLease;
use Kumwe\CMS\Extension\Infrastructure\ExtensionRegistryFenceAllocator;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use Kumwe\CMS\Presentation\Application\AdministratorThemeRecovery;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use RuntimeException;

/**
 * Runs administrator theme recovery from the console, holding the same locks a normal theme change holds.
 *
 * The recovery itself is `DoctrineAdministratorThemeRecovery`; this adapter supplies the mutual
 * exclusion it assumes. Recovery mutates the extension registry, so it must not interleave with an
 * install, an activation, or a trust-key rotation: this class takes the extension lifecycle lock, then
 * the cross-process `extension-registry` Redis lease, then allocates a database fence, and hands the
 * inner service a lease carrying both. It is the implementation the container binds for the confirmed
 * `theme:administrator:recover` command, and is not reachable from HTTP.
 *
 * @since  2.0.0
 */
final readonly class ConsoleAdministratorThemeRecovery implements AdministratorThemeRecovery
{
    /**
     * Bind the console adapter to the recovery service and the locks it must be wrapped in.
     *
     * @param  DoctrineAdministratorThemeRecovery  $recovery    Transactional recovery run once the
     *         registry is locked.
     * @param  RedisRuntime                        $redis       Runtime the cross-process
     *         `extension-registry` lease is taken on.
     * @param  ExtensionRegistryFenceAllocator     $fences      Allocator of the monotonic database fence
     *         that invalidates a stale lease holder.
     * @param  TrustStore                          $trust       Owner of the extension lifecycle lock
     *         recovery is serialised against.
     * @param  object                              $capability  Break-glass token proving the caller is the
     *         console; must be the same instance the recovery service was constructed with.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DoctrineAdministratorThemeRecovery $recovery,
        private RedisRuntime $redis,
        private ExtensionRegistryFenceAllocator $fences,
        private TrustStore $trust,
        private object $capability,
    ) {
    }

    /**
     * Restores the built-in administrator theme under the extension lifecycle lock.
     *
     * @return  void
     *
     * @throws  RuntimeException  When another extension registry operation already holds the lease.
     *
     * @since   2.0.0
     */
    public function recover(): void
    {
        $this->trust->synchronizedLifecycle(fn (): mixed => $this->recoverLocked());
    }

    /**
     * Takes the registry lease and fence, then runs the recovery inside them.
     *
     * The lease is released in a `finally` block, so a failed recovery does not leave the registry
     * locked for the remainder of its 120-second time to live.
     *
     * @return  null  Always null; the lifecycle lock expects a value-returning operation.
     *
     * @throws  RuntimeException  When the registry lease is already held by another operation.
     *
     * @since   2.0.0
     */
    private function recoverLocked(): null
    {
        $mutex = $this->redis->acquireLease('extension-registry', 120);
        if ($mutex === null) {
            throw new RuntimeException('Another extension registry operation is already in progress.');
        }
        try {
            $lease = new DatabaseFencedExtensionRegistryLease($mutex, $this->fences->allocate());
            $this->recovery->recover($this->capability, $lease);
        } finally {
            $mutex->release();
        }

        return null;
    }
}
