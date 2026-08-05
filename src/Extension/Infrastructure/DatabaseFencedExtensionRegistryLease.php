<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure;

use Kumwe\CMS\Extension\Application\ExtensionRegistryLease;
use Kumwe\CMS\Infrastructure\Redis\RedisLease;

final readonly class DatabaseFencedExtensionRegistryLease implements ExtensionRegistryLease
{
    public function __construct(private RedisLease $mutex, private int $databaseFence)
    {
    }

    public function fence(): int
    {
        return $this->databaseFence;
    }

    public function renew(): void
    {
        $this->mutex->renew();
    }
}
