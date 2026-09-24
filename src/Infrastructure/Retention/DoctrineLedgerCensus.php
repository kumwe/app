<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Retention;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use Kumwe\App\Application\Retention\LedgerCensus;
use Kumwe\App\Application\Retention\LedgerCount;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Infrastructure\Persistence\BoundedStatementExecutor;
use Kumwe\App\Infrastructure\Persistence\StatementBudget;
use Kumwe\App\Infrastructure\Persistence\StatementBudgetExceeded;
use Kumwe\App\Infrastructure\Persistence\TableNames;

/**
 * Exact `COUNT(*)` of a hot store under a server-enforced statement timeout.
 *
 * The timeout is the engine's own, not a PHP alarm, so a cancelled scan releases its snapshot on the
 * server through `BoundedStatementExecutor`: MariaDB runs the statement under `SET STATEMENT
 * max_statement_time`, MySQL under the `MAX_EXECUTION_TIME` optimizer hint, and PostgreSQL with a
 * transaction-local `statement_timeout`. A cancellation is reported as a timed-out count, never as an
 * exception.
 *
 * @since  2.0.0
 */
final readonly class DoctrineLedgerCensus implements LedgerCensus
{
    /**
     * Bind the census to the connection and table names.
     *
     * @param  Connection  $database  Connection the counts run on.
     * @param  TableNames  $tables    Prefixed physical table names.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Count one store exactly, cancelling the statement at the timeout.
     *
     * @param   RetentionStore  $store                Store to count.
     * @param   int             $timeoutMilliseconds  Statement timeout, 1 to 30000.
     *
     * @return  LedgerCount  The exact count, or a timed-out outcome with a null count.
     *
     * @throws  InvalidArgumentException  When the timeout is outside its range.
     * @throws  DbalException  When the statement fails for a reason other than its timeout.
     *
     * @since   2.0.0
     */
    public function count(RetentionStore $store, int $timeoutMilliseconds = 5_000): LedgerCount
    {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > self::MAXIMUM_TIMEOUT_MILLISECONDS) {
            throw new InvalidArgumentException('A ledger census timeout must be between 1 and 30000 milliseconds.');
        }
        $table = $this->tables->quoted(DoctrineRetentionObserver::physicalTable($store));
        $started = hrtime(true);
        try {
            $rows = (new BoundedStatementExecutor($this->database))->fetchAll(
                sprintf('SELECT COUNT(*) AS counted FROM %s', $table),
                [],
                [],
                new StatementBudget($timeoutMilliseconds, 1_024),
            );
        } catch (StatementBudgetExceeded) {
            return new LedgerCount(
                $store,
                null,
                true,
                (hrtime(true) - $started) / 1_000_000,
                $timeoutMilliseconds,
                self::COST_CLASS,
            );
        }
        $counted = $rows[0]['counted'] ?? 0;

        return new LedgerCount(
            $store,
            is_int($counted) ? $counted : (is_string($counted) && is_numeric($counted) ? (int) $counted : 0),
            false,
            (hrtime(true) - $started) / 1_000_000,
            $timeoutMilliseconds,
            self::COST_CLASS,
        );
    }
}
