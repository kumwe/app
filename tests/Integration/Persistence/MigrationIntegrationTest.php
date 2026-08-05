<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\CMS\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\JobRecoveryMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DoctrineMigrationLock;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DoctrineMigrationRepository;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Kumwe\CMS\Delivery\Console\Command\MigrateCommand;
use Kumwe\CMS\Delivery\Console\Output;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(DoctrineMigrationLock::class)]
#[CoversClass(DoctrineMigrationRepository::class)]
#[CoversClass(CoreSchemaMigration::class)]
#[CoversClass(JobRecoveryMigration::class)]
#[CoversClass(ApplicationAuthorizationMigration::class)]
final class MigrationIntegrationTest extends TestCase
{
    public function testDatabaseMigrationIsIdempotentAndReady(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $command = $container->get(MigrateCommand::class);
        self::assertInstanceOf(MigrateCommand::class, $command);
        self::assertSame(0, $command->execute([], new class implements Output {
            public function line(string $message): void
            {
            }

            public function error(string $message): void
            {
            }
        }));
        self::assertTrue($container->get(ReadinessProbe::class)->ready());

        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertSame(
            '69741c8e3fc14a1a0e318a643deb3fa7901685ba8f534a1782917839ad1f0b57',
            (new CoreSchemaMigration($tables))->checksum(),
        );
        $schema = $database->createSchemaManager();
        self::assertTrue($schema->introspectTable($tables->raw('users'))->hasColumn('security_epoch'));
        $idempotency = $schema->introspectTable($tables->raw('idempotency'));
        foreach (
            ['authorization_fingerprint', 'lease_owner', 'lease_expires_at', 'owner_token', 'locked_until']
            as $column
        ) {
            self::assertTrue($idempotency->hasColumn($column));
        }
        self::assertTrue($schema->introspectTable($tables->raw('jobs'))->hasColumn('lease_token'));
        self::assertTrue($schema->tablesExist([
            $tables->raw('sites'),
            $tables->raw('resource_site_ownership'),
        ]));
        self::assertSame('default', $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['schedule', '00000000-0000-7000-8000-000000000801']));
        self::assertSame(ApplicationAuthorizationMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [ApplicationAuthorizationMigration::ID]));
        self::assertSame(JobRecoveryMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [JobRecoveryMigration::ID]));
        self::assertSame('default', $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['schedule', '00000000-0000-7000-8000-000000000802']));
        $legacyAdministrator = $database->fetchAssociative(sprintf(
            'SELECT id, security_epoch FROM %s WHERE email_normalized = ?',
            $tables->quoted('users'),
        ), ['integration-administrator@example.test']);
        if ($legacyAdministrator !== false) {
            self::assertSame('2', (string) $legacyAdministrator['security_epoch']);
            self::assertSame('2', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s g INNER JOIN %s r ON r.id = g.role_id '
                . "WHERE r.code = 'administrator' AND g.capability_code IN (?, ?)",
                $tables->quoted('role_capability_grants'),
                $tables->quoted('roles'),
            ), ['themes.site.manage', 'themes.administrator.manage']));
        }
    }
}
