<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\InvalidArgumentException as DbalInvalidArgument;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Persistence\BoundedStatementExecutor;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\StatementBudget;
use Kumwe\App\Infrastructure\Persistence\StatementBudgetExceeded;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the engine itself cancels a bounded statement at its time budget, that a result passing its byte
 * budget is refused, and that neither bound leaks into, or breaks, the caller's session or transaction.
 *
 * @since  2.0.0
 */
#[CoversClass(BoundedStatementExecutor::class)]
#[CoversClass(StatementBudget::class)]
#[CoversClass(StatementBudgetExceeded::class)]
final class BoundedStatementExecutorIntegrationTest extends TestCase
{
    /**
     * Connection under test.
     *
     * @var    ?Connection
     * @since  2.0.0
     */
    private ?Connection $connection = null;

    /**
     * A two-second statement under a 250 ms budget is cancelled by the server well before it would end.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheEngineCancelsAStatementAtItsTimeBudget(): void
    {
        $executor = new BoundedStatementExecutor($this->connection());
        $started = hrtime(true);
        try {
            $executor->fetchAll($this->sleepSql(), [], [], new StatementBudget(250));
            self::fail('A statement longer than its time budget must be cancelled.');
        } catch (StatementBudgetExceeded $exceeded) {
            self::assertSame(StatementBudgetExceeded::TIME, $exceeded->bound);
            self::assertSame(250, $exceeded->limit);
            self::assertInstanceOf(DbalException::class, $exceeded->getPrevious());
            self::assertTrue(BoundedStatementExecutor::timedOut($exceeded->getPrevious()));
        }
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        self::assertLessThan(1_500.0, $elapsed, 'The engine, not the statement, must end the wait.');
        self::assertEquals(1, $this->connection()->fetchOne('SELECT 1'));
    }

    /**
     * Inside a caller transaction the transaction survives a cancellation and keeps its own timeout.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACallerTransactionSurvivesACancellationAndKeepsItsOwnTimeout(): void
    {
        $connection = $this->connection();
        $executor = new BoundedStatementExecutor($connection);
        $connection->beginTransaction();
        try {
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $connection->executeStatement("SET LOCAL statement_timeout = '45s'");
            }
            $before = $this->callerTimeout($connection);
            try {
                $executor->fetchAll($this->sleepSql(), [], [], new StatementBudget(200));
                self::fail('A statement longer than its time budget must be cancelled.');
            } catch (StatementBudgetExceeded $exceeded) {
                self::assertSame(StatementBudgetExceeded::TIME, $exceeded->bound);
            }
            self::assertSame($before, $this->callerTimeout($connection));
            $rows = $executor->fetchAll(
                'SELECT CONCAT(CAST(? AS CHAR(7)), CAST(? AS CHAR(5))) AS joined',
                ['bounded', '-read'],
                [],
                new StatementBudget(1_000),
            );
            self::assertSame([['joined' => 'bounded-read']], $rows);
            self::assertSame($before, $this->callerTimeout($connection));
            self::assertTrue($connection->isTransactionActive());
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * A result whose column bytes pass the budget is refused; the same result under a wider budget is read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAResultPassingItsByteBudgetIsRefused(): void
    {
        $executor = new BoundedStatementExecutor($this->connection());
        $sql = "SELECT REPEAT('x', 2000) AS payload UNION ALL SELECT REPEAT('y', 2000) AS payload";
        try {
            $executor->fetchAll($sql, [], [], new StatementBudget(5_000, 3_000));
            self::fail('A result wider than its byte budget must be refused.');
        } catch (StatementBudgetExceeded $exceeded) {
            self::assertSame(StatementBudgetExceeded::BYTES, $exceeded->bound);
            self::assertSame(3_000, $exceeded->limit);
            self::assertNull($exceeded->getPrevious());
            self::assertSame('The statement result passed its 3000 byte budget.', $exceeded->getMessage());
        }
        $rows = $executor->fetchAll($sql, [], [], new StatementBudget(5_000, 4_000));
        self::assertCount(2, $rows);
        self::assertSame(str_repeat('y', 2000), $rows[1]['payload']);
        self::assertEquals(1, $this->connection()->fetchOne('SELECT 1'));
    }

    /**
     * A statement failing for any other reason reaches the caller as the driver raised it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnyOtherDriverFailureIsRaisedUnchanged(): void
    {
        $executor = new BoundedStatementExecutor($this->connection());
        try {
            $executor->fetchAll(
                'SELECT missing_column FROM kumwe_bounded_statement_absent',
                [],
                [],
                new StatementBudget(),
            );
            self::fail('A missing table must fail.');
        } catch (DbalException $failure) {
            self::assertFalse(BoundedStatementExecutor::timedOut($failure));
        }
        self::assertFalse(BoundedStatementExecutor::timedOut(new DbalInvalidArgument('Not a driver failure.')));
    }

    /**
     * Only a single `SELECT` may run under a budget.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStatementOtherThanASelectIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BoundedStatementExecutor($this->connection()))->fetchAll(
            'DELETE FROM kumwe_bounded_statement_absent',
            [],
            [],
            new StatementBudget(),
        );
    }

    /**
     * Close the connection opened for the test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    /**
     * Open, once per test, a connection exactly as the runtime does.
     *
     * @return  Connection  Open connection to the integration database.
     *
     * @since   2.0.0
     */
    private function connection(): Connection
    {
        return $this->connection ??= (new DoctrineConnectionFactory(
            (new ConfigurationFactory())->create(Environment::fromGlobals())->database,
        ))->create();
    }

    /**
     * A two-second statement in which the sleep is only part of the query, so MySQL reports its cancellation.
     *
     * @return  string  Engine-specific statement.
     *
     * @since   2.0.0
     */
    private function sleepSql(): string
    {
        return $this->connection()->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'SELECT 1 AS slept FROM (SELECT pg_sleep(2)) AS waited'
            : 'SELECT 1 AS slept FROM (SELECT 1 AS n) AS waited WHERE SLEEP(2) = 0';
    }

    /**
     * Read the statement timeout the caller's session or transaction currently carries.
     *
     * @param   Connection  $connection  Connection to read.
     *
     * @return  string  The engine's current timeout setting.
     *
     * @since   2.0.0
     */
    private function callerTimeout(Connection $connection): string
    {
        $platform = $connection->getDatabasePlatform();

        $value = $connection->fetchOne(match (true) {
            $platform instanceof PostgreSQLPlatform => "SELECT current_setting('statement_timeout')",
            $platform instanceof MariaDBPlatform => 'SELECT @@SESSION.max_statement_time',
            default => 'SELECT @@SESSION.max_execution_time',
        });
        self::assertIsScalar($value);

        return (string) $value;
    }
}
