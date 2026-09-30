<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use InvalidArgumentException;
use Kumwe\App\Audit\Application\AuditRetentionAuthorityState;
use Kumwe\App\Audit\Application\AuditRetentionResult;
use Kumwe\App\Audit\Application\AuditRetentionService;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditRetentionAuthority;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditRetentionPrincipalSynchronizer;
use Kumwe\App\Audit\Infrastructure\Persistence\DeferredAuditRetentionService;
use Kumwe\App\Infrastructure\Persistence\Migration\AuditRetentionAuthorityMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\ExecutionContext;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AuditRetentionAuthority::class)]
#[CoversClass(AuditRetentionAuthorityMigration::class)]
#[CoversClass(AuditRetentionPrincipalSynchronizer::class)]
#[CoversClass(DeferredAuditRetentionService::class)]
final class AuditRetentionAuthorityTest extends TestCase
{
    /**
     * The principal-less SQLite test engine is reported as such and never pretends to be separated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSqliteTestEngineReportsThatItHasNoPrincipals(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');

        AuditRetentionAuthority::install($database, $tables);
        AuditRetentionAuthority::assign($database, $tables, 'kumwe_retention');
        (new AuditRetentionAuthorityMigration($tables))->up($database);

        self::assertTrue(AuditRetentionAuthority::installed($database, $tables));
        self::assertNull(AuditRetentionAuthority::principal($database, $tables));
        self::assertTrue(AuditRetentionAuthority::sessionAuthorized($database, $tables));
        self::assertSame(
            AuditRetentionAuthorityState::SinglePrincipal,
            AuditRetentionAuthority::state($database, $tables),
        );
        self::assertSame('0', (string) $database->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger'",
        ));
        self::assertFalse((new AuditRetentionPrincipalSynchronizer($database, $tables, ''))->synchronize());
    }

    /**
     * Only a plain login name can be assigned, and an unsupported engine is refused outright.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsafePrincipalsAndUnsupportedEnginesAreRefused(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (["kumwe'--", 'kumwe@host', '', str_repeat('k', 64), 'kumwe retention'] as $principal) {
            try {
                AuditRetentionAuthority::assign($sqlite, new TableNames($sqlite, 'kumwe_'), $principal);
                self::fail(sprintf('The principal "%s" must be refused.', $principal));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $oracle = $this->createStub(Connection::class);
        $oracle->method('getDatabasePlatform')->willReturn(new OraclePlatform());
        $tables = new TableNames($sqlite, 'kumwe_');
        foreach (
            [
                static fn () => AuditRetentionAuthority::install($oracle, $tables),
                static fn () => AuditRetentionAuthority::assign($oracle, $tables, 'kumwe_retention'),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('An engine without principals-aware triggers must be refused.');
            } catch (RuntimeException $refusal) {
                self::assertStringContainsString('cannot bind audit retention', $refusal->getMessage());
            }
        }
    }

    /**
     * A recognized privilege refusal leaves the boundary uninstalled; any other failure aborts the migration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMigrationAbsorbsOnlyAPrivilegeRefusal(): void
    {
        $tables = new TableNames(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), 'kumwe_');
        $migration = new AuditRetentionAuthorityMigration($tables);
        self::assertSame(AuditRetentionAuthorityMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $migration->checksum());

        $refused = new PDOException('CREATE ROUTINE command denied');
        $refused->errorInfo = ['42000', 1142, 'CREATE ROUTINE command denied'];
        $migration->up($this->mysqlFailingWith($refused));

        $broken = new PDOException('You have an error in your SQL syntax');
        $broken->errorInfo = ['42000', 1064, 'You have an error in your SQL syntax'];
        try {
            $migration->up($this->mysqlFailingWith($broken));
            self::fail('A definition error must abort the migration.');
        } catch (PDOException $failure) {
            self::assertSame($broken, $failure);
        }
    }

    /**
     * The retention connection is composed once, on the first pass, and every pass is delegated to it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDeferredServiceComposesItsConnectionOnlyOnFirstUse(): void
    {
        $built = 0;
        $inner = new class implements AuditRetentionService {
            /**
             * Windows the double was asked to enforce.
             *
             * @var    list<int>
             * @since  2.0.0
             */
            public array $windows = [];

            public function prune(ExecutionContext $context, int $retentionDays): AuditRetentionResult
            {
                $this->windows[] = $retentionDays;

                return new AuditRetentionResult(0);
            }
        };
        $deferred = new DeferredAuditRetentionService(static function () use (&$built, $inner): AuditRetentionService {
            $built++;

            return $inner;
        });
        self::assertSame(0, $built);
        $context = AuthorizationContext::siteScoped('audit.manage');

        self::assertSame(0, $deferred->prune($context, 30)->prunedCount);
        $deferred->prune($context, 40);

        self::assertSame(1, $built);
        self::assertSame([30, 40], $inner->windows);
    }

    /**
     * Build a MySQL-platform connection whose first definition statement fails with the given error.
     *
     * @param   PDOException  $error  Failure the server reports.
     *
     * @return  Connection  Connection double.
     *
     * @since   2.0.0
     */
    private function mysqlFailingWith(PDOException $error): Connection
    {
        $database = $this->createStub(Connection::class);
        $database->method('getDatabasePlatform')->willReturn(new MySQL84Platform());
        $database->method('fetchOne')->willReturn(false);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $database->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $database->method('executeStatement')->willThrowException($error);

        return $database;
    }
}
