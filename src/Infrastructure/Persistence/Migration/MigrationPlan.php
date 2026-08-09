<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use RuntimeException;

/**
 * The ordered migration contract understood by this binary.
 *
 * Applied rows must be an exact prefix. This deliberately rejects a newer database, gaps,
 * unknown migrations, and checksum drift instead of letting an incompatible binary become ready.
 * `MigrationRunner` consults it before applying anything and `ReadinessProbe` before reporting ready,
 * so a rollback onto an older build refuses to serve rather than migrating a schema backwards.
 *
 * @since  2.0.0
 */
final readonly class MigrationPlan
{
    /**
     * Migrations this binary ships, sorted by ID into the order they must be applied.
     *
     * @var    list<Migration>
     * @since  2.0.0
     */
    private array $migrations;

    /**
     * Checksums of superseded builds of a migration that are still accepted, keyed by migration ID.
     *
     * A site that ran an earlier build of a migration file carries that build's checksum in its
     * ledger. Listing the checksum here declares the two builds equivalent, so the upgrade proceeds
     * instead of failing as drift.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private array $acceptedHistoricalChecksums;

    /**
     * Validate and freeze the migration order this binary understands.
     *
     * Migrations may be supplied in any order; they are sorted by ID here. Every ID, checksum and
     * compatibility entry is checked up front, so a mis-assembled plan fails at construction rather
     * than part way through a migration run.
     *
     * @param   list<Migration>              $migrations                   Migrations this binary ships.
     * @param   array<string, list<string>>  $acceptedHistoricalChecksums  Earlier checksums still accepted.
     *
     * @throws  RuntimeException  When two migrations share an ID, an ID or checksum is malformed, or a
     *          compatibility entry names an unlisted migration or repeats a checksum.
     *
     * @since   2.0.0
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

    /**
     * Returns every migration in the plan, in the order they must be applied.
     *
     * @return  list<Migration>  Sorted by ID; a database's ledger must be an exact prefix of this sequence.
     *
     * @since   2.0.0
     */
    public function all(): array
    {
        return $this->migrations;
    }

    /**
     * Returns the ID of every migration in the plan, in apply order.
     *
     * `MigrationRunner` hands this to `NonTransactionalMigrationRecovery`, which refuses a recovery
     * journal holding an attempt for a migration this binary does not ship.
     *
     * @return  list<string>  Apply-ordered migration IDs.
     *
     * @since   2.0.0
     */
    public function ids(): array
    {
        return array_map(static fn (Migration $migration): string => $migration->id(), $this->migrations);
    }

    /**
     * Assert that a database's ledger is an exact prefix of this plan.
     *
     * This is the gate between an installed database and a deployed binary. It refuses a ledger longer
     * than the plan, meaning the database belongs to a newer build; any divergence at a position,
     * meaning a gap or a migration this binary does not ship; and a stored checksum that is neither
     * the migration's current checksum nor one registered as historically accepted.
     *
     * @param   array<string, string>  $applied  Ledger contents: migration ID to the checksum stored when it ran.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the ledger is longer than the plan, diverges from it at any
     *          position, or carries a checksum the plan does not accept.
     *
     * @since   2.0.0
     */
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

    /**
     * Returns the migrations a database has still to run.
     *
     * Compatibility is asserted first, so a caller never receives a pending list derived from a ledger
     * that does not belong to this plan.
     *
     * @param   array<string, string>  $applied  Ledger contents: migration ID to the checksum stored when it ran.
     *
     * @return  list<Migration>  The tail of the plan beyond the ledger; empty when the database is current.
     *
     * @throws  RuntimeException  When the ledger is not an exact prefix of this plan.
     *
     * @since   2.0.0
     */
    public function pending(array $applied): array
    {
        $this->assertCompatible($applied);

        return array_slice($this->migrations, count($applied));
    }

    /**
     * Reports whether a database has already run every migration in this plan.
     *
     * `ReadinessProbe` gates on this, so an instance whose schema lags the code it runs never reports
     * itself ready.
     *
     * @param   array<string, string>  $applied  Ledger contents: migration ID to the checksum stored when it ran.
     *
     * @return  bool  True only when the ledger covers the whole plan.
     *
     * @throws  RuntimeException  When the ledger is not an exact prefix of this plan.
     *
     * @since   2.0.0
     */
    public function complete(array $applied): bool
    {
        $this->assertCompatible($applied);

        return count($applied) === count($this->migrations);
    }
}
