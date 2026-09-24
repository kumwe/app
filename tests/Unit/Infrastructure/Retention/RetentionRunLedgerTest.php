<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Retention;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Application\Retention\RetentionDrainResult;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Retention\RetentionRunLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins that the run ledger keeps one latest run per store and never reports a run it cannot read.
 *
 * The drain rate an operator sees is derived from the latest run, so a second run must replace the first
 * rather than add a row, a store that never ran must read as "no run", and a row whose timestamp or counters
 * are unreadable must not be turned into a plausible-looking rate.
 *
 * @since  2.0.0
 */
#[CoversClass(RetentionRunLedger::class)]
final class RetentionRunLedgerTest extends TestCase
{
    /**
     * A second run replaces the first, and a store that never ran reads as no run at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheLatestRunReplacesThePreviousOneAndAnUnrunStoreHasNone(): void
    {
        $database = self::database();
        $ledger = new RetentionRunLedger($database, new TableNames($database, 'kumwe_'));
        $ledger->record(
            new RetentionDrainResult(RetentionStore::JobHistory, 40, 2, 0.5, 200, false, true),
            new DateTimeImmutable('2026-09-24T10:00:00+00:00'),
        );
        $ledger->record(
            new RetentionDrainResult(RetentionStore::JobHistory, 7, 1, 0.25, 100, true, false),
            new DateTimeImmutable('2026-09-24T11:00:00+00:00'),
        );

        $latest = $ledger->latest(RetentionStore::JobHistory);

        self::assertNotNull($latest);
        self::assertSame('2026-09-24 11:00:00', $latest['ran_at']->format('Y-m-d H:i:s'));
        self::assertSame(7, $latest['rows_drained']);
        self::assertSame(250, $latest['elapsed_ms']);
        self::assertTrue($latest['backlog_cleared']);
        self::assertFalse($latest['budget_exhausted']);
        self::assertSame('1', (string) $database->fetchOne('SELECT COUNT(*) FROM kumwe_retention_runs'));
        self::assertNull($ledger->latest(RetentionStore::ProcessHistory));
    }

    /**
     * An unreadable run timestamp yields no run, and unreadable counters read as zero rather than guessed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnreadableRunsAreNotTurnedIntoPlausibleRates(): void
    {
        $database = self::database();
        $ledger = new RetentionRunLedger($database, new TableNames($database, 'kumwe_'));
        $database->insert('kumwe_retention_runs', [
            'store' => 'job_history', 'ran_at' => 'not a timestamp', 'rows_drained' => 5, 'batches' => 1,
            'elapsed_ms' => 10, 'final_batch' => 100, 'backlog_cleared' => 1, 'budget_exhausted' => 0,
        ]);
        $database->insert('kumwe_retention_runs', [
            'store' => 'process_history', 'ran_at' => '2026-09-24 11:00:00', 'rows_drained' => 'many',
            'batches' => 1, 'elapsed_ms' => -4, 'final_batch' => 100, 'backlog_cleared' => 'yes',
            'budget_exhausted' => 't',
        ]);

        self::assertNull($ledger->latest(RetentionStore::JobHistory));
        $garbled = $ledger->latest(RetentionStore::ProcessHistory);
        self::assertNotNull($garbled);
        self::assertSame(0, $garbled['rows_drained']);
        self::assertSame(0, $garbled['elapsed_ms']);
        self::assertFalse($garbled['backlog_cleared']);
        self::assertTrue($garbled['budget_exhausted']);
    }

    /**
     * Open an in-memory engine holding the run ledger table.
     *
     * @return  Connection  Engine with an empty ledger.
     *
     * @since   2.0.0
     */
    private static function database(): Connection
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database->executeStatement(
            'CREATE TABLE kumwe_retention_runs (store TEXT PRIMARY KEY, ran_at TEXT, rows_drained, batches INTEGER, '
            . 'elapsed_ms, final_batch INTEGER, backlog_cleared, budget_exhausted)',
        );

        return $database;
    }
}
