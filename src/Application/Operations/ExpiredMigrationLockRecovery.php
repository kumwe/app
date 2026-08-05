<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Operations;

interface ExpiredMigrationLockRecovery
{
    public function recoverExpiredLegacyOwner(string $expectedOwnerToken): void;
}
