<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Result;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Infrastructure\Persistence\BoundedStatementExecutor;
use Kumwe\App\Infrastructure\Persistence\StatementBudget;
use Kumwe\App\Infrastructure\Persistence\StatementBudgetExceeded;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Retention\DoctrineLedgerCensus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins how each MySQL-family engine is asked to bound a statement and how its cancellation is recognised,
 * including MySQL's optimizer hint, which no local engine in this suite runs.
 *
 * @since  2.0.0
 */
#[CoversClass(BoundedStatementExecutor::class)]
#[CoversClass(DoctrineLedgerCensus::class)]
final class BoundedStatementExecutorTest extends TestCase
{
    /**
     * MySQL receives the execution-time hint inside the `SELECT`, and its error 3024 is a time refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMySqlCarriesTheHintAndReportsError3024AsTheTimeBound(): void
    {
        $sent = [];
        $executor = new BoundedStatementExecutor($this->connection(new MySQL84Platform(), $sent, 3024));
        try {
            $executor->fetchAll('  select id FROM kumwe_jobs', [], [], new StatementBudget(250));
            self::fail('Error 3024 must be reported as the time bound.');
        } catch (StatementBudgetExceeded $exceeded) {
            self::assertSame(StatementBudgetExceeded::TIME, $exceeded->bound);
            self::assertSame(250, $exceeded->limit);
        }
        self::assertSame(['SELECT /*+ MAX_EXECUTION_TIME(250) */ id FROM kumwe_jobs'], $sent);
    }

    /**
     * MariaDB receives the bound as a statement prefix, and its error 1969 is a time refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMariaDbCarriesTheStatementPrefixAndReportsError1969AsTheTimeBound(): void
    {
        $sent = [];
        $executor = new BoundedStatementExecutor($this->connection(new MariaDBPlatform(), $sent, 1969));
        $this->expectException(StatementBudgetExceeded::class);
        try {
            $executor->fetchAll('SELECT 1', [], [], new StatementBudget(1_500));
        } finally {
            self::assertSame(['SET STATEMENT max_statement_time = 1.500000 FOR SELECT 1'], $sent);
        }
    }

    /**
     * A driver failure with any other code is raised unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnyOtherDriverCodeIsRaisedUnchanged(): void
    {
        $sent = [];
        $executor = new BoundedStatementExecutor($this->connection(new MariaDBPlatform(), $sent, 1146));
        $this->expectException(DriverException::class);
        $executor->fetchAll('SELECT 1', [], [], new StatementBudget());
    }

    /**
     * An engine without a supported timeout runs the statement as written and still enforces the bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEngineWithoutATimeoutRunsTheStatementUnchanged(): void
    {
        $sent = [];
        $executor = new BoundedStatementExecutor($this->connection(new SQLitePlatform(), $sent, null, [
            ['label' => 'alpha', 'amount' => 3, 'note' => null],
        ]));
        self::assertSame(
            [['label' => 'alpha', 'amount' => 3, 'note' => null]],
            $executor->fetchAll('SELECT label FROM t', [], [], new StatementBudget(1_000, 13)),
        );
        self::assertSame(['SELECT label FROM t'], $sent);
    }

    /**
     * The census reports an engine cancellation as a timed-out count rather than raising it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCensusReportsACancelledCountAsTimedOut(): void
    {
        $sent = [];
        $connection = $this->connection(new MariaDBPlatform(), $sent, 1969);
        $count = (new DoctrineLedgerCensus($connection, new TableNames($connection, 'kumwe_')))
            ->count(RetentionStore::JobHistory, 40);
        self::assertTrue($count->timedOut);
        self::assertNull($count->rows);
        self::assertSame(40, $count->timeoutMilliseconds);
        self::assertStringStartsWith('SET STATEMENT max_statement_time = 0.040000 FOR SELECT COUNT(*)', $sent[0]);
    }

    /**
     * Build a connection stub that records what it is sent and fails or answers as told.
     *
     * @param   AbstractPlatform                 $platform  Platform to report.
     * @param   list<string>                     $sent      Receives each statement sent.
     * @param   ?int                             $failWith  Driver error code to raise, or null to answer.
     * @param   list<array<string, mixed>>       $rows      Rows to answer with.
     *
     * @return  Connection  Stub connection.
     *
     * @since   2.0.0
     */
    private function connection(AbstractPlatform $platform, array &$sent, ?int $failWith, array $rows = []): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $driverResult = $this->createStub(DriverResult::class);
        $driverResult->method('fetchAssociative')->willReturnOnConsecutiveCalls(...[...$rows, false]);
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql) use (&$sent, $failWith, $driverResult, $connection): Result {
                $sent[] = $sql;
                if ($failWith !== null) {
                    throw new DriverException(
                        new class ('Query execution was interrupted', 'HY000', $failWith) extends AbstractException {
                        },
                        null,
                    );
                }

                return new Result($driverResult, $connection);
            },
        );

        return $connection;
    }
}
