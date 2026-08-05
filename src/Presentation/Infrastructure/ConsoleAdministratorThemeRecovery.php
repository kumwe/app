<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Infrastructure;

use Kumwe\CMS\Extension\Infrastructure\DatabaseFencedExtensionRegistryLease;
use Kumwe\CMS\Extension\Infrastructure\ExtensionRegistryFenceAllocator;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use Kumwe\CMS\Presentation\Application\AdministratorThemeRecovery;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use RuntimeException;

final readonly class ConsoleAdministratorThemeRecovery implements AdministratorThemeRecovery
{
    public function __construct(
        private DoctrineAdministratorThemeRecovery $recovery,
        private RedisRuntime $redis,
        private ExtensionRegistryFenceAllocator $fences,
        private TrustStore $trust,
        private object $capability,
    ) {
    }

    public function recover(): void
    {
        $this->trust->synchronizedLifecycle(fn (): mixed => $this->recoverLocked());
    }

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
