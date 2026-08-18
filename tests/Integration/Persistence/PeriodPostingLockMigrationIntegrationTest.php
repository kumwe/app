<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DriverException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\CMS\BusinessRecord\Domain\PostingPeriod;
use Kumwe\CMS\BusinessRecord\Domain\PostingPeriodStatus;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrinePostingPeriodRepository;
use Kumwe\CMS\Infrastructure\Persistence\Migration\PeriodPostingLockMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Drives the posting-period migration itself, rather than inspecting a schema it already produced.
 *
 * An installed database says only that the migration ran once, somewhere; it cannot say that a re-run
 * after an interrupted attempt is a no-op, that the digest-derived constraint names are the ones the
 * replay recognises, or that the range rule is the engine's own rather than an application convention.
 * Those are properties of the transformation, so they are proven by applying the transformation here —
 * to a private copy of the tables it touches, under a per-test prefix, so a run cannot disturb the
 * installation the rest of the suite shares. The repository is then pointed at the same private table,
 * which is what proves the scope-precedence reads against the exact schema the migration installs.
 *
 * The suite is MySQL-family only: the charset copy onto the site foreign key exists for InnoDB's
 * collation rules and has no counterpart on PostgreSQL, where the whole portable half is already
 * proven by the migration running in every engine's pipeline.
 *
 * @since  2.0.0
 */
#[CoversClass(PeriodPostingLockMigration::class)]
#[CoversClass(DoctrinePostingPeriodRepository::class)]
final class PeriodPostingLockMigrationIntegrationTest extends TestCase
{
    /**
     * One application installs the table, its rules and the capabilities; a replay changes nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOneApplicationInstallsTheLockAndAReplayChangesNothing(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);

        try {
            $this->createSupportTables($database, $tables);
            $migration = new PeriodPostingLockMigration($tables);
            $migration->up($database);
            $migration->up($database);

            $manager = $database->createSchemaManager();
            $periods = $manager->introspectTableByUnquotedName($tables->raw('business_posting_periods'));
            self::assertTrue($periods->hasIndex($tables->raw('uq_posting_period_identity')));
            self::assertTrue($periods->getIndex($tables->raw('uq_posting_period_identity'))->isUnique());
            self::assertFalse($periods->getColumn('reopened_by')->getNotnull());
            $foreignKeys = $periods->getForeignKeys();
            self::assertCount(1, $foreignKeys);
            foreach (array_keys($foreignKeys) as $name) {
                self::assertMatchesRegularExpression('/^fk_[0-9a-f]{24}$/D', (string) $name);
            }
            $checks = $database->fetchFirstColumn(
                'SELECT c.CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS c '
                . 'INNER JOIN information_schema.TABLE_CONSTRAINTS t '
                . 'ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME '
                . "WHERE c.CONSTRAINT_SCHEMA = DATABASE() AND t.TABLE_NAME = ? AND t.CONSTRAINT_TYPE = 'CHECK'",
                [$tables->raw('business_posting_periods')],
            );
            self::assertCount(1, $checks, 'A replay must retain exactly one range check.');
            self::assertMatchesRegularExpression('/^ck_[0-9a-f]{24}$/D', (string) $checks[0]);

            foreach (['business.period.manage' => 1, 'business.period.read' => 0] as $capability => $impact) {
                $row = $database->fetchAssociative(sprintf(
                    'SELECT high_impact FROM %s WHERE code = ?',
                    $tables->quoted('capabilities'),
                ), [$capability]);
                self::assertIsArray($row, $capability);
                self::assertSame($impact, (int) $row['high_impact'], $capability);
                self::assertSame('2', (string) $database->fetchOne(sprintf(
                    'SELECT COUNT(*) FROM %s WHERE resource_type = ?',
                    $tables->quoted('resource_site_ownership'),
                ), ['capability']));
            }
            self::assertSame('2', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE role_id = ?',
                $tables->quoted('role_capability_grants'),
            ), ['role-admin']), 'Both capabilities are granted to the administrator role exactly once.');
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT security_epoch FROM %s WHERE id = ?',
                $tables->quoted('users'),
            ), ['user-admin']), 'Administrators must re-establish sessions after the grant.');
        } finally {
            $this->dropTables($database, $tables);
        }
    }

    /**
     * The installed rules are the engine's own, and the repository resolves scope precedence over them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheInstalledRulesAreEnforcedAndTheRepositoryResolvesScopePrecedence(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);

        try {
            $this->createSupportTables($database, $tables);
            (new PeriodPostingLockMigration($tables))->up($database);
            $repository = new DoctrinePostingPeriodRepository($database, $tables);

            $this->assertDriverRefuses(
                static function () use ($database, $tables): void {
                    $database->insert($tables->raw('business_posting_periods'), [
                        'id' => Uuid::uuid7()->toString(),
                        'site_identifier' => 'default',
                        'organization_identifier' => '',
                        'period_key' => 'inverted',
                        'starts_at' => '2026-09-01 00:00:00',
                        'ends_at' => '2026-08-01 00:00:00',
                        'status' => 'closed',
                        'closed_by' => 'actor-1',
                        'closed_at' => '2026-09-05 08:00:00',
                    ]);
                },
                'A range that ends before it starts must be refused by the engine.',
            );
            $this->assertDriverRefuses(
                static function () use ($database, $tables): void {
                    $database->insert($tables->raw('business_posting_periods'), [
                        'id' => Uuid::uuid7()->toString(),
                        'site_identifier' => 'default',
                        'organization_identifier' => '',
                        'period_key' => 'strange',
                        'starts_at' => '2026-08-01 00:00:00',
                        'ends_at' => '2026-09-01 00:00:00',
                        'status' => 'frozen',
                        'closed_by' => 'actor-1',
                        'closed_at' => '2026-09-05 08:00:00',
                    ]);
                },
                'A status outside the declared pair must be refused by the engine.',
            );

            $sitewide = new PostingPeriod(
                'default',
                null,
                '2026-08',
                new DateTimeImmutable('2026-08-01T00:00:00Z'),
                new DateTimeImmutable('2026-09-01T00:00:00Z'),
                PostingPeriodStatus::Closed,
                'actor-1',
                new DateTimeImmutable('2026-09-05T08:00:00Z'),
            );
            $repository->save($sitewide);
            $organizationOwn = new PostingPeriod(
                'default',
                'acme',
                'acme-2026-08',
                new DateTimeImmutable('2026-08-01T00:00:00Z'),
                new DateTimeImmutable('2026-09-01T00:00:00Z'),
                PostingPeriodStatus::Open,
                'actor-2',
                new DateTimeImmutable('2026-09-05T09:00:00Z'),
            );
            $repository->save($organizationOwn);
            $this->assertDriverRefuses(
                static function () use ($database, $tables): void {
                    // A second row under the same (site, organization, key) identity must hit the
                    // unique index directly, so the key stays unambiguous for every reader.
                    $database->insert($tables->raw('business_posting_periods'), [
                        'id' => Uuid::uuid7()->toString(),
                        'site_identifier' => 'default',
                        'organization_identifier' => '',
                        'period_key' => '2026-08',
                        'starts_at' => '2026-08-01 00:00:00',
                        'ends_at' => '2026-09-01 00:00:00',
                        'status' => 'closed',
                        'closed_by' => 'actor-9',
                        'closed_at' => '2026-09-05 08:00:00',
                    ]);
                },
                'One scope must hold at most one declaration per key.',
            );

            $inside = new DateTimeImmutable('2026-08-15T12:00:00Z');
            $siteAnswer = $repository->closedPeriodContaining('default', null, $inside);
            self::assertSame('2026-08', $siteAnswer?->key);
            self::assertNull(
                $repository->closedPeriodContaining('default', null, new DateTimeImmutable('2026-09-01T00:00:00Z')),
                'The range is half-open: its end instant is already outside.',
            );

            // The organization's own open declaration wins the calendar read, while the closed
            // site-wide one still answers the lock's closed-range question.
            self::assertSame('acme-2026-08', $repository->periodContaining('default', 'acme', $inside)?->key);
            self::assertSame('2026-08', $repository->closedPeriodContaining('default', 'acme', $inside)?->key);
            self::assertSame('2026-08', $repository->periodContaining('default', null, $inside)?->key);

            $reopened = $sitewide->reopened('actor-3', new DateTimeImmutable('2026-09-10T09:00:00Z'));
            $repository->save($reopened);
            self::assertNull($repository->closedPeriodContaining('default', null, $inside));
            $stored = $repository->find('default', null, '2026-08');
            self::assertSame('actor-3', $stored?->reopenedBy);
            self::assertSame(
                ['2026-08', 'acme-2026-08'],
                array_map(
                    static fn (PostingPeriod $period): string => $period->key,
                    $repository->listFor('default', 'acme'),
                ),
            );
            self::assertSame(
                ['2026-08'],
                array_map(
                    static fn (PostingPeriod $period): string => $period->key,
                    $repository->listFor('default', 'other'),
                ),
            );

            $database->executeStatement(sprintf(
                'DELETE FROM %s WHERE identifier = ?',
                $tables->quoted('sites'),
            ), ['default']);
            self::assertSame('0', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('business_posting_periods'),
            )), 'Retiring a site takes its posting periods with it.');
        } finally {
            $this->dropTables($database, $tables);
        }
    }

    /**
     * Open the installation connection, skipping the suite where the copied definition has no meaning.
     *
     * @return  Connection  MySQL-family integration connection.
     *
     * @since   2.0.0
     */
    private function connection(): Connection
    {
        $database = TestKernelFactory::create(Environment::fromGlobals())->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Copying a character definition applies only to MySQL-family databases.');
        }

        return $database;
    }

    /**
     * Compile table names under a prefix no other test or installation can be using.
     *
     * @param   Connection  $database  Integration connection supplying identifier quoting.
     *
     * @return  TableNames  Compiler bound to a prefix unique to this test method.
     *
     * @since   2.0.0
     */
    private function tables(Connection $database): TableNames
    {
        return new TableNames($database, 'p' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10) . '_');
    }

    /**
     * Create the pre-existing tables the migration reads, seeds and references.
     *
     * These are minimal private copies carrying exactly the columns the migration touches: the sites
     * parent whose charset the foreign key copies, the capability catalogue, the administrator role,
     * its user, and the ownership registry the seeding records into.
     *
     * @param   Connection  $database  Integration connection the tables are created on.
     * @param   TableNames  $tables    Unique test table-name compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createSupportTables(Connection $database, TableNames $tables): void
    {
        $statements = [
            sprintf(
                'CREATE TABLE %s (identifier VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci '
                . 'NOT NULL, PRIMARY KEY (identifier)) ENGINE = InnoDB',
                $tables->quoted('sites'),
            ),
            sprintf(
                'CREATE TABLE %s (code VARCHAR(191) NOT NULL, description TEXT NOT NULL, '
                . 'owner_kind VARCHAR(32) NOT NULL, owner_identifier VARCHAR(191) NOT NULL, '
                . 'allowed_scopes TEXT NOT NULL, delegable TINYINT(1) NOT NULL, '
                . 'high_impact TINYINT(1) NOT NULL, definition_version INT NOT NULL, '
                . 'definition_checksum VARCHAR(64) NOT NULL, lifecycle_state VARCHAR(32) NOT NULL, '
                . 'PRIMARY KEY (code)) ENGINE = InnoDB',
                $tables->quoted('capabilities'),
            ),
            sprintf(
                'CREATE TABLE %s (id VARCHAR(64) NOT NULL, code VARCHAR(64) NOT NULL, PRIMARY KEY (id)) '
                . 'ENGINE = InnoDB',
                $tables->quoted('roles'),
            ),
            sprintf(
                'CREATE TABLE %s (id VARCHAR(64) NOT NULL, role_id VARCHAR(64) NOT NULL, '
                . 'capability_code VARCHAR(191) NOT NULL, scope_type VARCHAR(32) NOT NULL, '
                . 'scope_identifier VARCHAR(191) NULL, granted_at DATETIME NOT NULL, '
                . 'granted_by VARCHAR(64) NULL, PRIMARY KEY (id)) ENGINE = InnoDB',
                $tables->quoted('role_capability_grants'),
            ),
            sprintf(
                'CREATE TABLE %s (id VARCHAR(64) NOT NULL, security_epoch INT NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (id)) ENGINE = InnoDB',
                $tables->quoted('users'),
            ),
            sprintf(
                'CREATE TABLE %s (user_id VARCHAR(64) NOT NULL, role_id VARCHAR(64) NOT NULL, '
                . 'PRIMARY KEY (user_id, role_id)) ENGINE = InnoDB',
                $tables->quoted('user_roles'),
            ),
            sprintf(
                'CREATE TABLE %s (resource_type VARCHAR(64) NOT NULL, resource_id VARCHAR(191) NOT NULL, '
                . 'site_identifier VARCHAR(191) NULL, scope_level VARCHAR(16) NOT NULL, '
                . 'group_identifier VARCHAR(191) NULL, PRIMARY KEY (resource_type, resource_id)) '
                . 'ENGINE = InnoDB',
                $tables->quoted('resource_site_ownership'),
            ),
        ];
        foreach ($statements as $statement) {
            $database->executeStatement($statement);
        }
        $database->insert($tables->raw('sites'), ['identifier' => 'default']);
        $database->insert($tables->raw('roles'), ['id' => 'role-admin', 'code' => 'administrator']);
        $database->insert($tables->raw('users'), ['id' => 'user-admin', 'security_epoch' => 0]);
        $database->insert($tables->raw('user_roles'), ['user_id' => 'user-admin', 'role_id' => 'role-admin']);
    }

    /**
     * Assert that one deliberately invalid write is refused by the database itself.
     *
     * @param   callable(): void  $write    Invalid write whose driver exception proves enforcement.
     * @param   string            $message  Failure message used when the write unexpectedly succeeds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertDriverRefuses(callable $write, string $message): void
    {
        try {
            $write();
            self::fail($message);
        } catch (DriverException) {
            // Reaching the driver refusal is the property under test.
        }
    }

    /**
     * Remove every test table, the referencing one first so the constraint never blocks the cleanup.
     *
     * @param   Connection  $database  Integration connection the tables live on.
     * @param   TableNames  $tables    Unique test table-name compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function dropTables(Connection $database, TableNames $tables): void
    {
        $names = [
            'business_posting_periods',
            'resource_site_ownership',
            'user_roles',
            'users',
            'role_capability_grants',
            'roles',
            'capabilities',
            'sites',
        ];
        foreach ($names as $name) {
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tables->quoted($name)));
        }
    }
}
