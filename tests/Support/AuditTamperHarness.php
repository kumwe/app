<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditAppendOnlyGuard;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditRetentionAuthority;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditRetentionGuard;
use Kumwe\App\Infrastructure\Persistence\TableNames;

/**
 * Test-only harness that removes the append-only guards so a test can tamper with the audit trail.
 *
 * The verifier's whole purpose is to detect writes the platform is built to prevent, so proving it works
 * requires performing exactly the writes production refuses. That capability lives here, in the test
 * support tree, rather than anywhere in `src`: production code has no route past the guards other than
 * the archived, anchored retention window.
 */
final class AuditTamperHarness
{
    /** Drops every append-only guard, leaving the audit table writable for the tampering that follows. */
    public static function disableGuards(Connection $database, TableNames $tables): void
    {
        $platform = $database->getDatabasePlatform();
        foreach (
            [
            'audit_append_only_update' => 'audit_events',
            'audit_append_only_delete' => 'audit_events',
            'audit_retention_delete' => 'audit_events',
            'audit_retention_truncate' => 'audit_events',
            'audit_ledger_update' => 'audit_anchors',
            'audit_ledger_delete' => 'audit_anchors',
            'audit_ledger_truncate' => 'audit_anchors',
            'audit_retention_authority' => 'audit_events',
            'audit_prune_authority' => 'audit_anchors',
            ] as $trigger => $table
        ) {
            $name = $database->quoteSingleIdentifier($tables->raw($trigger));
            if ($platform instanceof PostgreSQLPlatform) {
                $database->executeStatement(sprintf(
                    'DROP TRIGGER IF EXISTS %s ON %s',
                    $name,
                    $tables->quoted($table),
                ));
                continue;
            }
            $database->executeStatement(sprintf('DROP TRIGGER IF EXISTS %s', $name));
        }
    }

    /** Reinstalls the guards the same way the migration does. */
    public static function enableGuards(Connection $database, TableNames $tables): void
    {
        AuditAppendOnlyGuard::install($database, $tables);
        AuditRetentionGuard::install($database, $tables);
        AuditRetentionAuthority::install($database, $tables);
    }

    /**
     * Reports whether the guards refuse an update of the given row, undoing the write when they do not.
     *
     * The probe has to attempt the real statement, because whether the database refuses it is exactly
     * the question. On a server with no guards the statement therefore succeeds, and an unrestored
     * success would leave the shared trail permanently divergent — every later test in the run would
     * then fail with a digest mismatch that has nothing to do with what it was testing. The previous
     * value is put back so the probe answers its question without becoming the tampering.
     */
    public static function updateIsRefused(Connection $database, TableNames $tables, string $id): bool
    {
        $outcome = $database->fetchOne(sprintf(
            'SELECT outcome FROM %s WHERE id = ?',
            $tables->quoted('audit_events'),
        ), [$id]);
        try {
            $database->executeStatement(sprintf(
                'UPDATE %s SET outcome = ? WHERE id = ?',
                $tables->quoted('audit_events'),
            ), ['denied', $id]);
        } catch (\Throwable) {
            return true;
        }
        if (is_string($outcome)) {
            $database->executeStatement(sprintf(
                'UPDATE %s SET outcome = ? WHERE id = ?',
                $tables->quoted('audit_events'),
            ), [$outcome, $id]);
        }

        return false;
    }

    /**
     * Reports whether the guards refuse a delete of the given row, restoring it when they do not.
     *
     * Like `updateIsRefused()`, the probe must really try the delete, so on an unguarded server the row
     * genuinely goes away. It is re-inserted exactly as it was — position, digest and witness link
     * included — so the probe leaves the trail as it found it.
     */
    public static function deleteIsRefused(Connection $database, TableNames $tables, string $id): bool
    {
        $row = $database->fetchAssociative(sprintf(
            'SELECT id, occurred_at, actor_id, action, subject_type, subject_id, outcome, metadata, '
            . 'position, digest, previous_digest FROM %s WHERE id = ?',
            $tables->quoted('audit_events'),
        ), [$id]);
        try {
            $database->executeStatement(sprintf(
                'DELETE FROM %s WHERE id = ?',
                $tables->quoted('audit_events'),
            ), [$id]);
        } catch (\Throwable) {
            return true;
        }
        if (is_array($row)) {
            if (is_resource($row['metadata'])) {
                $row['metadata'] = stream_get_contents($row['metadata']);
            }
            $database->insert($tables->raw('audit_events'), $row);
        }

        return false;
    }

    /**
     * Resets fixtures using test-only DDL authority; production retention cannot erase its own evidence.
     *
     * The kernel's retained checkpoints describe the erased trail, so they are withdrawn with it: a
     * deliberate fixture reset is exactly the rollback they would otherwise, correctly, report.
     */
    public static function truncateTrail(Connection $database, TableNames $tables): void
    {
        self::disableGuards($database, $tables);
        try {
            $database->executeStatement(sprintf('DELETE FROM %s', $tables->quoted('audit_events')));
            $database->executeStatement(sprintf('DELETE FROM %s', $tables->quoted('audit_anchors')));
        } finally {
            self::enableGuards($database, $tables);
        }
        self::withdrawKernelCheckpoints();
    }

    /** Removes the checkpoints the test kernel's verifier retained beneath the repository storage root. */
    public static function withdrawKernelCheckpoints(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/storage/private/audit-archives/checkpoints/*/*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    /** Swaps the stored positions of two rows, the reordering only an anchor can detect. */
    public static function swapPositions(Connection $database, TableNames $tables, string $first, string $second): void
    {
        $table = $tables->quoted('audit_events');
        $spare = 9_000_000;
        $positionOf = static fn (string $id): int => (int) $database->fetchOne(sprintf(
            'SELECT position FROM %s WHERE id = ?',
            $table,
        ), [$id]);
        $firstPosition = $positionOf($first);
        $secondPosition = $positionOf($second);
        $move = static function (string $id, int $position) use ($database, $table): void {
            $database->executeStatement(sprintf(
                'UPDATE %s SET position = ? WHERE id = ?',
                $table,
            ), [$position, $id]);
        };
        $move($first, $spare);
        $move($second, $firstPosition);
        $move($first, $secondPosition);
    }
}
