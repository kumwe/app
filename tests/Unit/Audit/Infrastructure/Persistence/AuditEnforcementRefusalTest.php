<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Query;
use Kumwe\App\Audit\Domain\AuditEnforcementState;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditEnforcementRefusal;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AuditEnforcementRefusal::class)]
#[CoversClass(AuditEnforcementState::class)]
final class AuditEnforcementRefusalTest extends TestCase
{
    /**
     * Name the driver refusals that must degrade enforcement instead of aborting the migration.
     *
     * The MySQL codes are quoted from a real failure: 1419 is what a server with binary logging enabled
     * answers a `CREATE TRIGGER` from an account without `SUPER`, which is how Amazon RDS, Cloud SQL and
     * Azure Database for MySQL are configured out of the box.
     *
     * @return  array<string, array{\Throwable}>  Named refusals, each as a single-argument case.
     *
     * @since   2.0.0
     */
    public static function refusals(): array
    {
        return [
            'mysql 1419 binlog without SUPER' => [self::mysql(
                1419,
                'HY000',
                'You do not have the SUPER privilege and binary logging is enabled',
            )],
            'mysql 1227 specific access denied' => [self::mysql(1227, '42000', 'Access denied; you need SUPER')],
            'mysql 1142 TRIGGER command denied' => [self::mysql(1142, '42000', 'TRIGGER command denied to user')],
            'postgres 42501 insufficient privilege' => [self::postgres()],
        ];
    }

    /**
     * Name the failures that must still abort loudly, because none of them is a privilege refusal.
     *
     * @return  array<string, array{\Throwable}>  Named non-refusals, each as a single-argument case.
     *
     * @since   2.0.0
     */
    public static function otherFailures(): array
    {
        return [
            'syntax error' => [new SyntaxErrorException(
                new PdoDriverException('You have an error in your SQL syntax', '42000', 1064),
                new Query('CREATE TRIGGER', [], []),
            )],
            'table missing' => [new TableNotFoundException(
                new PdoDriverException("Table 'kumwe_audit_events' doesn't exist", '42S02', 1146),
                new Query('CREATE TRIGGER', [], []),
            )],
            'server gone away' => [new ConnectionException(
                new PdoDriverException('MySQL server has gone away', 'HY000', 2006),
                new Query('CREATE TRIGGER', [], []),
            )],
            'not a driver failure at all' => [new RuntimeException('Something else broke entirely.')],
        ];
    }

    /**
     * Prove each recognised privilege refusal is matched.
     *
     * @param   \Throwable  $error  Failure a driver raised while creating an append-only trigger.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('refusals')]
    public function testPrivilegeRefusalsAreRecognised(\Throwable $error): void
    {
        self::assertTrue(AuditEnforcementRefusal::matches($error));
    }

    /**
     * Prove nothing else is swallowed, so a broken statement still fails the migration.
     *
     * @param   \Throwable  $error  Failure that is not a privilege refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('otherFailures')]
    public function testEveryOtherFailureIsLeftToPropagate(\Throwable $error): void
    {
        self::assertFalse(AuditEnforcementRefusal::matches($error));
    }

    /**
     * Prove the refusal is found through the wrapper DBAL actually hands the caller.
     *
     * The observed chain in the reported CI failure is `PDOException` wrapped by the driver exception
     * wrapped by `DriverException`, and the error code only exists on the inner links. Matching on the
     * outermost object alone would miss every real refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRefusalIsFoundThroughTheWholeExceptionChain(): void
    {
        $pdo = new PDOException('SQLSTATE[HY000]: General error: 1419 You do not have the SUPER privilege');
        $pdo->errorInfo = ['HY000', 1419, 'You do not have the SUPER privilege and binary logging is enabled'];

        self::assertTrue(AuditEnforcementRefusal::matches($pdo));
        self::assertTrue(AuditEnforcementRefusal::matches(new DriverException(
            PdoDriverException::new($pdo),
            new Query('CREATE TRIGGER', [], []),
        )));
    }

    /**
     * Prove the DBAL class of a refusal is not a usable signal, which is why codes are matched instead.
     *
     * Doctrine maps 1142 and 1227 onto `ConnectionException` and leaves 1419 a bare `DriverException`,
     * so three refusals of one kind arrive as two unrelated types while an unrelated dropped connection
     * arrives as the same type as two of them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsAreNotDistinguishableByTheirDoctrineExceptionClass(): void
    {
        $denied = self::mysql(1142, '42000', 'TRIGGER command denied to user');
        $lost = new ConnectionException(
            new PdoDriverException('MySQL server has gone away', 'HY000', 2006),
            new Query('CREATE TRIGGER', [], []),
        );

        self::assertInstanceOf($lost::class, $denied);
        self::assertTrue(AuditEnforcementRefusal::matches($denied));
        self::assertFalse(AuditEnforcementRefusal::matches($lost));
    }

    /**
     * Prove the two states describe themselves differently enough that a reader cannot confuse them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheEnforcementStatesReadAsMateriallyDifferentVerdicts(): void
    {
        self::assertTrue(AuditEnforcementState::Active->installed());
        self::assertFalse(AuditEnforcementState::NotInstalled->installed());
        self::assertSame('active', AuditEnforcementState::Active->value);
        self::assertSame('not_installed', AuditEnforcementState::NotInstalled->value);
        self::assertStringContainsString('NOT installed', AuditEnforcementState::NotInstalled->summary());
        self::assertStringContainsString(
            'application-level discipline',
            AuditEnforcementState::NotInstalled->summary(),
        );
        self::assertStringNotContainsString('NOT', AuditEnforcementState::Active->summary());
    }

    /**
     * Build the DBAL exception a MySQL driver error of the given code actually arrives as.
     *
     * @param   int     $code      MySQL error number the server reported.
     * @param   string  $sqlState  SQLSTATE accompanying it.
     * @param   string  $message   Server message text.
     *
     * @return  DriverException  The wrapper Doctrine's MySQL converter produces for that code.
     *
     * @since   2.0.0
     */
    private static function mysql(int $code, string $sqlState, string $message): DriverException
    {
        $driver = new PdoDriverException($message, $sqlState, $code);
        $query = new Query('CREATE TRIGGER', [], []);

        return in_array($code, [1142, 1227], true)
            ? new ConnectionException($driver, $query)
            : new DriverException($driver, $query);
    }

    /**
     * Build the DBAL exception PostgreSQL raises when the role may not create the trigger.
     *
     * @return  DriverException  Wrapper carrying SQLSTATE 42501, `insufficient_privilege`.
     *
     * @since   2.0.0
     */
    private static function postgres(): DriverException
    {
        return new DriverException(
            new PdoDriverException('ERROR: permission denied for table kumwe_audit_events', '42501', 7),
            new Query('CREATE TRIGGER', [], []),
        );
    }
}
