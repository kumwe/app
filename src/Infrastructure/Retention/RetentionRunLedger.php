<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Retention;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Retention\RetentionDrainResult;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Infrastructure\Persistence\TableNames;

/**
 * Durable record of each store's latest drain run, which is where the drain-rate metric comes from.
 *
 * A drain rate cannot be read from the ledger being drained: the rows that were removed are gone, and
 * counting what remains says nothing about how fast it went. The drain therefore writes one row per
 * store after every run — rows removed, batches, wall time, the batch size it converged on and why it
 * stopped — and the observer reads that row by primary key. The table holds at most one row per store,
 * so both sides are a single indexed statement regardless of how much the ledgers hold.
 *
 * @since  2.0.0
 */
final readonly class RetentionRunLedger
{
    /**
     * Bind the ledger to the connection and table names.
     *
     * @param  Connection  $database  Connection the run rows are written and read on.
     * @param  TableNames  $tables    Resolver for the prefixed `retention_runs` table.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Record a run, replacing the store's previous row.
     *
     * @param   RetentionDrainResult  $result  Outcome of the run.
     * @param   DateTimeImmutable     $ranAt   Instant the run finished.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(RetentionDrainResult $result, DateTimeImmutable $ranAt): void
    {
        $mysql = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $columns = 'ran_at = ?, rows_drained = ?, batches = ?, elapsed_ms = ?, final_batch = ?, '
            . 'backlog_cleared = ?, budget_exhausted = ?';
        $suffix = $mysql
            ? ' ON DUPLICATE KEY UPDATE ' . $columns
            : ' ON CONFLICT (store) DO UPDATE SET ' . $columns;
        $values = [
            $ranAt,
            $result->rowsDrained,
            $result->batches,
            (int) round($result->elapsedSeconds * 1_000),
            $result->finalBatch,
            $result->backlogCleared,
            $result->budgetExhausted,
        ];
        $types = [
            Types::DATETIME_IMMUTABLE, Types::BIGINT, Types::INTEGER, Types::INTEGER, Types::INTEGER,
            Types::BOOLEAN, Types::BOOLEAN,
        ];
        $this->database->executeStatement(sprintf(
            'INSERT INTO %s (store, ran_at, rows_drained, batches, elapsed_ms, final_batch, backlog_cleared, '
            . 'budget_exhausted) VALUES (?, ?, ?, ?, ?, ?, ?, ?)%s',
            $this->tables->quoted('retention_runs'),
            $suffix,
        ), [$result->store->value, ...$values, ...$values], [Types::STRING, ...$types, ...$types]);
    }

    /**
     * Read a store's latest run.
     *
     * @param   RetentionStore  $store  Store to read.
     *
     * @return  ?array{ran_at: DateTimeImmutable, rows_drained: int, elapsed_ms: int, backlog_cleared: bool,
     *          budget_exhausted: bool}  The latest run, or null when the store has never been drained.
     *
     * @since   2.0.0
     */
    public function latest(RetentionStore $store): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT ran_at, rows_drained, elapsed_ms, backlog_cleared, budget_exhausted FROM %s WHERE store = ?',
            $this->tables->quoted('retention_runs'),
        ), [$store->value]);
        if ($row === false) {
            return null;
        }
        $ranAt = $row['ran_at'] ?? null;
        $parsed = $ranAt instanceof DateTimeImmutable
            ? $ranAt
            : (is_string($ranAt) ? date_create_immutable($ranAt) : false);
        if ($parsed === false) {
            return null;
        }

        return [
            'ran_at' => $parsed,
            'rows_drained' => self::integer($row['rows_drained'] ?? 0),
            'elapsed_ms' => self::integer($row['elapsed_ms'] ?? 0),
            'backlog_cleared' => self::boolean($row['backlog_cleared'] ?? false),
            'budget_exhausted' => self::boolean($row['budget_exhausted'] ?? false),
        ];
    }

    /**
     * Normalise a driver integer.
     *
     * @param   mixed  $value  Driver value.
     *
     * @return  int  Non-negative integer; zero for anything else.
     *
     * @since   2.0.0
     */
    private static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value) && is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Normalise a driver boolean.
     *
     * @param   mixed  $value  Driver value.
     *
     * @return  bool  True for `true`, `1` and `'1'`.
     *
     * @since   2.0.0
     */
    private static function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
