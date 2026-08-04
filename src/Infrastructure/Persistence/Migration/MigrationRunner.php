<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Joomla\Database\DatabaseInterface;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use RuntimeException;

final readonly class MigrationRunner
{
    /**
     * @param list<Migration> $migrations
     */
    public function __construct(
        private DatabaseInterface $database,
        private MigrationRepository $repository,
        private MigrationLock $lock,
        private TransactionManager $transactions,
        private array $migrations,
    ) {
    }

    public function migrate(): MigrationResult
    {
        return $this->lock->synchronized(function (): MigrationResult {
            $this->repository->ensureLedger();
            $applied = $this->repository->applied();
            $pending = $this->orderedMigrations();
            $completed = [];

            foreach ($pending as $migration) {
                $id = $migration->id();
                $checksum = $migration->checksum();

                if (isset($applied[$id])) {
                    if (!hash_equals($applied[$id], $checksum)) {
                        throw new RuntimeException(sprintf('Migration checksum drift detected for "%s".', $id));
                    }

                    continue;
                }

                $started = hrtime(true);
                $this->transactions->transactional(function () use ($migration, $id, $checksum, $started): void {
                    $migration->up($this->database);
                    $elapsed = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
                    $this->repository->record($id, $checksum, $elapsed);
                });
                $completed[] = $id;
            }

            return new MigrationResult($completed);
        });
    }

    /**
     * @return list<Migration>
     */
    public function pending(): array
    {
        $this->repository->ensureLedger();
        $applied = $this->repository->applied();

        return array_values(array_filter(
            $this->orderedMigrations(),
            static fn (Migration $migration): bool => !isset($applied[$migration->id()]),
        ));
    }

    /**
     * @return list<Migration>
     */
    private function orderedMigrations(): array
    {
        $migrations = $this->migrations;
        usort($migrations, static fn (Migration $left, Migration $right): int => $left->id() <=> $right->id());
        $seen = [];

        foreach ($migrations as $migration) {
            if (isset($seen[$migration->id()])) {
                throw new RuntimeException(sprintf('Duplicate migration ID "%s".', $migration->id()));
            }

            if (preg_match('/^[0-9]{14}_[a-z0-9_]+$/', $migration->id()) !== 1) {
                throw new RuntimeException(sprintf('Migration ID "%s" is invalid.', $migration->id()));
            }

            if (preg_match('/^[a-f0-9]{64}$/', $migration->checksum()) !== 1) {
                throw new RuntimeException(sprintf('Migration checksum for "%s" is invalid.', $migration->id()));
            }

            $seen[$migration->id()] = true;
        }

        return $migrations;
    }
}
