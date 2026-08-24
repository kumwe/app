<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use Doctrine\DBAL\DriverManager;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioHostSessionMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioHostSessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real migration and Doctrine opaque-session store through SQLite DBAL.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineStudioHostSessionRepository::class)]
#[CoversClass(StudioHostSessionMigration::class)]
final class StudioHostSessionPersistenceTest extends TestCase
{
    /**
     * Replay seeds five modes once and round-trips only trusted non-secret binding coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMigrationSeedsModesAndOpaqueBindingsRoundTripWithoutPolicyOrCredentialData(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $this->createAuthorityTables($database, $tables);
        $migration = new StudioHostSessionMigration($tables);

        $migration->up($database);
        $migration->up($database);

        self::assertSame('20260824020000_studio_host_sessions', $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        self::assertSame(5, (int) $database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE code LIKE 'studio.mode.%%'",
            $tables->quoted('capabilities'),
        )));
        self::assertSame(5, (int) $database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE capability_code LIKE 'studio.mode.%%'",
            $tables->quoted('role_capability_grants'),
        )));
        self::assertSame(10, (int) $database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE resource_type IN ('capability', 'grant')",
            $tables->quoted('resource_site_ownership'),
        )));
        self::assertSame(2, (int) $database->fetchOne(sprintf(
            'SELECT security_epoch FROM %s WHERE id = ?',
            $tables->quoted('users'),
        ), ['user-1']));

        $repository = new DoctrineStudioHostSessionRepository($database, $tables);
        $session = new StudioHostSession(
            'contexts/persistence',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            'publisher-namibia',
            'organization-1',
            'workspace-1',
            'administrator',
            hash('sha256', 'administrator-session-test'),
            StudioSessionMode::Hybrid,
            StudioResourceKind::Content,
            'content-private',
            'session-generation-1',
        );
        $repository->add($session);

        self::assertEquals($session, $repository->find('contexts/persistence'));
        self::assertNull($repository->find('contexts/unknown'));
        $columns = array_keys($database->createSchemaManager()->introspectTableByUnquotedName(
            $tables->raw('studio_host_sessions'),
        )->getColumns());
        self::assertNotContains('credential', $columns);
        self::assertNotContains('policy_reason', $columns);
        self::assertNotContains('permissions', $columns);
    }

    /**
     * Create the authority catalog subset that precedes this append-only migration.
     *
     * @param   \Doctrine\DBAL\Connection  $database  In-memory SQLite integration connection.
     * @param   TableNames                  $tables    Prefix-aware test table compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createAuthorityTables(\Doctrine\DBAL\Connection $database, TableNames $tables): void
    {
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (code VARCHAR(191) NOT NULL PRIMARY KEY, description VARCHAR(500) NOT NULL, '
                . 'owner_kind VARCHAR(20) NOT NULL, owner_identifier VARCHAR(191) NOT NULL, '
                . 'allowed_scopes TEXT NOT NULL, delegable BOOLEAN NOT NULL, high_impact BOOLEAN NOT NULL, '
                . 'definition_version INTEGER NOT NULL, definition_checksum VARCHAR(64) NOT NULL, '
                . 'lifecycle_state VARCHAR(20) NOT NULL)',
            $tables->quoted('capabilities'),
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (id VARCHAR(191) NOT NULL PRIMARY KEY, code VARCHAR(191) NOT NULL)',
            $tables->quoted('roles'),
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (id VARCHAR(191) NOT NULL PRIMARY KEY, role_id VARCHAR(191) NOT NULL, '
                . 'capability_code VARCHAR(191) NOT NULL, scope_type VARCHAR(63) NOT NULL, '
                . 'scope_identifier VARCHAR(191) NULL, granted_at DATETIME NOT NULL, granted_by VARCHAR(191) NULL)',
            $tables->quoted('role_capability_grants'),
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (id VARCHAR(191) NOT NULL PRIMARY KEY, security_epoch INTEGER NOT NULL)',
            $tables->quoted('users'),
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (user_id VARCHAR(191) NOT NULL, role_id VARCHAR(191) NOT NULL)',
            $tables->quoted('user_roles'),
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (resource_type VARCHAR(63) NOT NULL, resource_id VARCHAR(191) NOT NULL, '
                . 'site_identifier VARCHAR(191) NOT NULL, scope_level VARCHAR(20) NOT NULL, '
                . 'group_identifier VARCHAR(191) NULL, PRIMARY KEY (resource_type, resource_id))',
            $tables->quoted('resource_site_ownership'),
        ));
        $database->insert($tables->raw('roles'), ['id' => 'role-1', 'code' => 'administrator']);
        $database->insert($tables->raw('users'), ['id' => 'user-1', 'security_epoch' => 1]);
        $database->insert($tables->raw('user_roles'), ['user_id' => 'user-1', 'role_id' => 'role-1']);
    }
}
