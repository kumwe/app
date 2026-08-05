<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

/** Durable recovery boundary for database platforms whose DDL commits implicitly. */
interface NonTransactionalMigrationRecovery
{
    /** @param list<string> $knownMigrationIds */
    public function assertKnownAttempts(array $knownMigrationIds): void;

    public function hasUnresolvedAttempts(): bool;

    public function prepare(Migration $migration): NonTransactionalMigrationAction;

    public function complete(Migration $migration): void;

    public function reconcileApplied(Migration $migration): void;
}
