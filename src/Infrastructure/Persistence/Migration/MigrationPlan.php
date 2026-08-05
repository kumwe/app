<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use RuntimeException;

/**
 * The ordered migration contract understood by this binary.
 *
 * Applied rows must be an exact prefix. This deliberately rejects a newer database, gaps,
 * unknown migrations, and checksum drift instead of letting an incompatible binary become ready.
 */
final readonly class MigrationPlan
{
    /** @var list<Migration> */
    private array $migrations;

    /** @var array<string, list<string>> */
    private array $acceptedHistoricalChecksums;

    /**
     * @param list<Migration> $migrations
     * @param array<string, list<string>> $acceptedHistoricalChecksums
     */
    public function __construct(array $migrations, array $acceptedHistoricalChecksums = [])
    {
        usort($migrations, static fn (Migration $left, Migration $right): int => $left->id() <=> $right->id());
        $seen = [];
        foreach ($migrations as $migration) {
            $id = $migration->id();
            if (isset($seen[$id])) {
                throw new RuntimeException(sprintf('Duplicate migration ID "%s".', $id));
            }
            if (preg_match('/^[0-9]{14}_[a-z0-9_]+$/D', $id) !== 1) {
                throw new RuntimeException(sprintf('Migration ID "%s" is invalid.', $id));
            }
            if (preg_match('/^[a-f0-9]{64}$/D', $migration->checksum()) !== 1) {
                throw new RuntimeException(sprintf('Migration checksum for "%s" is invalid.', $id));
            }
            $seen[$id] = true;
        }

        foreach ($acceptedHistoricalChecksums as $id => $checksums) {
            if (!isset($seen[$id]) || !array_is_list($checksums)) {
                throw new RuntimeException(sprintf('Historical migration compatibility for "%s" is invalid.', $id));
            }
            foreach ($checksums as $checksum) {
                if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
                    throw new RuntimeException(sprintf('Historical migration checksum for "%s" is invalid.', $id));
                }
            }
            if (count($checksums) !== count(array_unique($checksums))) {
                throw new RuntimeException(sprintf('Historical migration checksums for "%s" are duplicated.', $id));
            }
        }

        $this->migrations = $migrations;
        $this->acceptedHistoricalChecksums = $acceptedHistoricalChecksums;
    }

    /** @return list<Migration> */
    public function all(): array
    {
        return $this->migrations;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn (Migration $migration): string => $migration->id(), $this->migrations);
    }

    /** @param array<string, string> $applied */
    public function assertCompatible(array $applied): void
    {
        $appliedIds = array_keys($applied);
        sort($appliedIds, SORT_STRING);
        if (count($appliedIds) > count($this->migrations)) {
            throw new RuntimeException('The migration ledger belongs to a newer or incompatible schema.');
        }

        foreach ($appliedIds as $index => $id) {
            $expected = $this->migrations[$index] ?? null;
            if ($expected === null || $expected->id() !== $id) {
                throw new RuntimeException(sprintf(
                    'The migration ledger is not an exact prefix at "%s".',
                    $id,
                ));
            }
            $checksum = $applied[$id] ?? null;
            $accepted = [$expected->checksum(), ...($this->acceptedHistoricalChecksums[$id] ?? [])];
            if (!is_string($checksum) || !in_array($checksum, $accepted, true)) {
                throw new RuntimeException(sprintf('Migration checksum drift detected for "%s".', $id));
            }
        }
    }

    /** @param array<string, string> $applied @return list<Migration> */
    public function pending(array $applied): array
    {
        $this->assertCompatible($applied);

        return array_slice($this->migrations, count($applied));
    }

    /** @param array<string, string> $applied */
    public function complete(array $applied): bool
    {
        $this->assertCompatible($applied);

        return count($applied) === count($this->migrations);
    }
}
