<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException as AbstractDriverError;
use Doctrine\DBAL\Driver\Exception as DriverError;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\Extension\Runtime\RuntimeIdentity;
use Kumwe\App\Extension\Runtime\RuntimeLeaseWriter;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(RuntimeLeaseWriter::class)]
final class RuntimeLeaseWriterTest extends TestCase
{
    public function testTheLeaseIsClaimedWithOneStatementBindingTheKeyOnceAndTheValuesTwice(): void
    {
        $statements = [];
        $writer = new RuntimeLeaseWriter(
            $this->connection(new MariaDBPlatform(), $statements),
            $this->tables(),
            null,
        );

        $writer->renew(...$this->lease());

        self::assertCount(1, $statements, 'A lease claim must not need a second statement.');
        self::assertCount(19, $statements[0]['parameters']);
        self::assertCount(19, $statements[0]['types']);
        self::assertSame('lease-key', $statements[0]['parameters'][0]);
        self::assertSame(
            array_slice($statements[0]['parameters'], 1, 9),
            array_slice($statements[0]['parameters'], 10, 9),
            'The written values must be bound identically for the insert and the update.',
        );
    }

    /** @return iterable<string, array{AbstractPlatform, string}> */
    public static function platforms(): iterable
    {
        yield 'mariadb' => [new MariaDBPlatform(), 'ON DUPLICATE KEY UPDATE '];
        yield 'mysql' => [new MySQLPlatform(), 'ON DUPLICATE KEY UPDATE '];
        yield 'postgresql' => [new PostgreSQLPlatform(), 'ON CONFLICT (replica_id) DO UPDATE SET '];
    }

    #[DataProvider('platforms')]
    public function testTheUpsertUsesEachPlatformsOwnConflictClause(
        AbstractPlatform $platform,
        string $clause,
    ): void {
        $statements = [];
        $writer = new RuntimeLeaseWriter($this->connection($platform, $statements), $this->tables(), null);

        $writer->renew(...$this->lease());

        self::assertStringContainsString($clause, $statements[0]['sql']);
        self::assertStringNotContainsString('VALUES(', $statements[0]['sql'], 'Deprecated MySQL syntax.');
    }

    public function testASnapshotIsolationConflictIsRetriedAndThenReported(): void
    {
        $statements = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Extension runtime lease write met a concurrent peer write.',
            self::callback(static fn (array $context): bool => $context['attempt'] === 1
                && $context['will_retry'] === true
                && $context['replica_id'] === 'lease-key'),
        );
        $failures = [$this->recordChanged()];
        $writer = new RuntimeLeaseWriter(
            $this->connection(new MariaDBPlatform(), $statements, $failures),
            $this->tables(),
            $logger,
        );

        $writer->renew(...$this->lease());

        self::assertCount(2, $statements, 'The conflicting write must be repeated once and then succeed.');
    }

    public function testADeadlockIsTreatedAsTheSameConcurrencyOnEveryOtherDriver(): void
    {
        $statements = [];
        $failures = [new DeadlockException($this->driverError(1213), null)];
        $writer = new RuntimeLeaseWriter(
            $this->connection(new PostgreSQLPlatform(), $statements, $failures),
            $this->tables(),
            null,
        );

        $writer->renew(...$this->lease());

        self::assertCount(2, $statements);
    }

    public function testExhaustedRetriesLeaveTheRenewalToThePeerAndSaySo(): void
    {
        $statements = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(3))->method('warning');
        $failures = [$this->recordChanged(), $this->recordChanged(), $this->recordChanged()];
        $writer = new RuntimeLeaseWriter(
            $this->connection(new MariaDBPlatform(), $statements, $failures),
            $this->tables(),
            $logger,
        );

        $writer->renew(...$this->lease());

        self::assertCount(3, $statements, 'The write must be bounded to three attempts.');
    }

    public function testAFaultThatIsNotConcurrencyIsNeverSwallowed(): void
    {
        $statements = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $failures = [new ForeignKeyConstraintViolationException($this->driverError(1452), null)];
        $writer = new RuntimeLeaseWriter(
            $this->connection(new MariaDBPlatform(), $statements, $failures),
            $this->tables(),
            $logger,
        );

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $writer->renew(...$this->lease());
    }

    /** Build a DriverException carrying MariaDB's bare ER_CHECKREAD, which no DBAL class maps. */
    private function recordChanged(): DriverException
    {
        return new DriverException($this->driverError(1020), null);
    }

    /** Build the driver-level error a real PDO failure would be wrapped from. */
    private function driverError(int $code): DriverError
    {
        return new class ('Record has changed since last read', 'HY000', $code) extends AbstractDriverError {
        };
    }

    /** @return array{string, RuntimeIdentity, int, string, string, DateTimeImmutable, DateTimeImmutable} */
    private function lease(): array
    {
        return [
            'lease-key',
            new RuntimeIdentity('deployment', 'replica', 'process', 'instance'),
            7,
            str_repeat('a', 64),
            str_repeat('b', 64),
            new DateTimeImmutable('2026-08-13 08:00:00'),
            new DateTimeImmutable('2026-08-13 08:05:00'),
        ];
    }

    private function tables(): TableNames
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('quoteSingleIdentifier')
            ->willReturnCallback(static fn (string $name): string => '`' . $name . '`');

        return new TableNames($connection, 'kumwe_');
    }

    /**
     * Build a connection recording every statement and failing the leading attempts on demand.
     *
     * @param  list<array{sql: string, parameters: list<mixed>, types: list<mixed>}>  $statements
     * @param  list<DriverException>                                                  $failures
     */
    private function connection(
        AbstractPlatform $platform,
        array &$statements,
        array $failures = [],
    ): Connection {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters, array $types) use (&$statements, &$failures): int {
                $statements[] = ['sql' => $sql, 'parameters' => $parameters, 'types' => $types];
                $failure = array_shift($failures);
                if ($failure !== null) {
                    throw $failure;
                }

                return 1;
            },
        );

        return $connection;
    }
}
