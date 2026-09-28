<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;

/**
 * Runs one read-only `SELECT` under a server-enforced execution time and a materialized byte budget.
 *
 * The timeout is the engine's own, never a PHP alarm, so a cancelled statement stops examining rows and
 * releases its snapshot on the server: MariaDB runs it under `SET STATEMENT max_statement_time`, MySQL
 * under the `MAX_EXECUTION_TIME` optimizer hint, and PostgreSQL inside a transaction, or a savepoint of
 * the caller's transaction, with a transaction-local `statement_timeout` that ends with the statement.
 * Rows are then materialized one at a time and their column bytes summed, so a result that passes the
 * byte budget is released and refused rather than decoded or serialized. Either bound raises
 * `StatementBudgetExceeded`; every other driver failure reaches the caller as raised.
 *
 * @since  2.0.0
 */
final readonly class BoundedStatementExecutor
{
    /**
     * Savepoint a PostgreSQL statement runs after when the caller already holds a transaction.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SAVEPOINT = 'kumwe_statement_budget';

    /**
     * Bind the executor to the connection the statements run on.
     *
     * @param  Connection  $database  Connection whose platform decides how the timeout is expressed.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database)
    {
    }

    /**
     * Fetch every row of a bounded `SELECT`.
     *
     * @param   string                                              $sql         A single `SELECT`.
     * @param   list<mixed>                                         $parameters  Positional bindings.
     * @param   list<string|ParameterType|Type|ArrayParameterType>  $types       Binding types.
     * @param   StatementBudget                                     $budget      Time and byte bounds.
     *
     * @return  list<array<string, mixed>>  The rows, in the order the statement returned them.
     *
     * @throws  StatementBudgetExceeded  When the engine cancelled the statement at its timeout or the
     *          materialized rows passed the byte budget.
     * @throws  InvalidArgumentException  When the statement is not a single `SELECT`.
     * @throws  DbalException  When the driver rejects the statement for any other reason.
     *
     * @since   2.0.0
     */
    public function fetchAll(string $sql, array $parameters, array $types, StatementBudget $budget): array
    {
        if (preg_match('/^\s*SELECT\b/i', $sql) !== 1) {
            throw new InvalidArgumentException('Only a single SELECT statement can run under a statement budget.');
        }
        $platform = $this->database->getDatabasePlatform();
        try {
            if ($platform instanceof PostgreSQLPlatform) {
                return $this->postgresql($sql, $parameters, $types, $budget);
            }
            if ($platform instanceof MariaDBPlatform) {
                $sql = sprintf(
                    'SET STATEMENT max_statement_time = %F FOR %s',
                    $budget->timeoutMilliseconds / 1_000,
                    ltrim($sql),
                );
            } elseif ($platform instanceof AbstractMySQLPlatform) {
                $sql = (string) preg_replace(
                    '/^\s*SELECT\b/i',
                    sprintf('SELECT /*+ MAX_EXECUTION_TIME(%d) */', $budget->timeoutMilliseconds),
                    $sql,
                    1,
                );
            }

            return $this->materialize($sql, $parameters, $types, $budget, $this->database);
        } catch (DbalException $failure) {
            if (self::timedOut($failure)) {
                throw new StatementBudgetExceeded(
                    StatementBudgetExceeded::TIME,
                    $budget->timeoutMilliseconds,
                    $failure,
                );
            }
            throw $failure;
        }
    }

    /**
     * Recognise an engine's statement-timeout cancellation.
     *
     * @param   DbalException  $failure  Failure raised by a bounded statement.
     *
     * @return  bool  True for MariaDB error 1969, MySQL error 3024 or PostgreSQL SQLSTATE 57014.
     *
     * @since   2.0.0
     */
    public static function timedOut(DbalException $failure): bool
    {
        if (!$failure instanceof DriverException) {
            return false;
        }

        return in_array($failure->getCode(), [1969, 3024], true) || $failure->getSQLState() === '57014';
    }

    /**
     * Run the statement with a transaction-local PostgreSQL timeout that ends with the statement.
     *
     * Without a caller transaction the timeout is set inside a transaction opened here and ends with it.
     * Inside one, the timeout is set after a savepoint and the savepoint is rolled back once the rows are
     * read, which discards the timeout and, after a cancellation, returns the caller's transaction to a
     * usable state. The statement is a plain `SELECT` that takes no row locks, so rolling back to the
     * savepoint discards nothing else. Both bookkeeping steps are one round trip each, because a
     * parameterless PostgreSQL call accepts several commands.
     *
     * @param   string                                              $sql         A single `SELECT`.
     * @param   list<mixed>                                         $parameters  Positional bindings.
     * @param   list<string|ParameterType|Type|ArrayParameterType>  $types       Binding types.
     * @param   StatementBudget                                     $budget      Time and byte bounds.
     *
     * @return  list<array<string, mixed>>  The rows.
     *
     * @throws  StatementBudgetExceeded  When the rows pass the byte budget.
     * @throws  DbalException  When the driver rejects a statement, including at the timeout.
     *
     * @since   2.0.0
     */
    private function postgresql(string $sql, array $parameters, array $types, StatementBudget $budget): array
    {
        $timeout = sprintf("SET LOCAL statement_timeout = '%dms'", $budget->timeoutMilliseconds);
        if (!$this->database->isTransactionActive()) {
            return $this->database->transactional(function (Connection $connection) use (
                $sql,
                $parameters,
                $types,
                $budget,
                $timeout,
            ): array {
                $connection->executeStatement($timeout);

                return $this->materialize($sql, $parameters, $types, $budget, $connection);
            });
        }
        $this->database->executeStatement(sprintf('SAVEPOINT %s; %s', self::SAVEPOINT, $timeout));
        try {
            return $this->materialize($sql, $parameters, $types, $budget, $this->database);
        } finally {
            $this->database->executeStatement(sprintf(
                'ROLLBACK TO SAVEPOINT %1$s; RELEASE SAVEPOINT %1$s',
                self::SAVEPOINT,
            ));
        }
    }

    /**
     * Materialize rows one at a time, refusing the result once its column bytes pass the budget.
     *
     * A string or binary value counts its length, any other non-null scalar eight bytes.
     *
     * @param   string                                              $sql         Statement as sent.
     * @param   list<mixed>                                         $parameters  Positional bindings.
     * @param   list<string|ParameterType|Type|ArrayParameterType>  $types       Binding types.
     * @param   StatementBudget                                     $budget      Byte bound.
     * @param   Connection                                          $connection  Connection to run on.
     *
     * @return  list<array<string, mixed>>  The rows.
     *
     * @throws  StatementBudgetExceeded  When the rows pass the byte budget.
     * @throws  DbalException  When the driver rejects the statement.
     *
     * @since   2.0.0
     */
    private function materialize(
        string $sql,
        array $parameters,
        array $types,
        StatementBudget $budget,
        Connection $connection,
    ): array {
        $result = $connection->executeQuery($sql, $parameters, $types);
        $rows = [];
        $bytes = 0;
        while (($row = $result->fetchAssociative()) !== false) {
            foreach ($row as $value) {
                $bytes += is_string($value) ? strlen($value) : ($value === null ? 0 : 8);
            }
            if ($bytes > $budget->maximumBytes) {
                $result->free();
                throw new StatementBudgetExceeded(StatementBudgetExceeded::BYTES, $budget->maximumBytes);
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
