<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Automation\Scheduler;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessRecordHistoryWindowMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ResourceOwnershipPortabilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(ResourceOwnershipPortabilityMigration::class)]
#[CoversClass(BusinessRecordHistoryWindowMigration::class)]
/**
 * Proves ownership lookups compare two columns the schema has actually pinned to one another.
 *
 * @since  2.0.0
 */
final class ResourceOwnershipPortabilityIntegrationTest extends TestCase
{
    /**
     * Proves the installed ownership column carries the canonical site identifier's character definition.
     *
     * This is the property the scheduler's dispatch claim depends on. It was previously left to a foreign
     * key that only MySQL-family engines enforce, and only where a recovery path had created it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheInstalledOwnershipColumnSharesTheCanonicalCharacterDefinition(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        $manager = $database->createSchemaManager();
        $canonical = $manager
            ->introspectTableByUnquotedName($tables->raw('sites'))
            ->getColumn('identifier');
        $ownership = $manager->introspectTableByUnquotedName($tables->raw('resource_site_ownership'));
        $referencing = $ownership->getColumn('site_identifier');

        self::assertSame($canonical->getCharset(), $referencing->getCharset());
        self::assertSame($canonical->getCollation(), $referencing->getCollation());
        $onSiteIdentifier = [];
        foreach ($ownership->getForeignKeys() as $name => $constraint) {
            if ($constraint->getUnquotedLocalColumns() === ['site_identifier']) {
                $onSiteIdentifier[] = $name;
            }
        }

        self::assertSame(
            [ResourceOwnershipPortabilityMigration::foreignKeyName($tables->raw('resource_site_ownership'))],
            $onSiteIdentifier,
            'The ownership constraint must carry the name both creation paths derive.',
        );
    }

    /**
     * Proves a scheduler dispatch pass runs against the repaired schema.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASchedulerDispatchPassRunsAgainstTheRepairedSchema(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $scheduler = $container->get(Scheduler::class);
        self::assertInstanceOf(Scheduler::class, $scheduler);

        self::assertGreaterThanOrEqual(
            0,
            $scheduler->dispatchDue(TestKernelFactory::schedulerContext($container), 5),
        );
    }

    /**
     * Proves the repair actually moves a column whose character definition has drifted apart.
     *
     * The divergence is built deliberately in a prefixed fixture, because the shipped schema's foreign key
     * makes it unreachable on a MySQL-family engine — and a partial schema that never gained that
     * constraint is exactly the case the repair exists for. Before the repair the two columns compare
     * across two collations; afterwards they share one, and the dispatch join's shape executes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRepairPinsAnOwnershipColumnThatHasDriftedFromTheCanonicalOne(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Deliberate collation divergence is a MySQL-family condition.');
        }

        $prefix = 'p' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10) . '_';
        $tables = new TableNames($database, $prefix);
        $sites = $tables->quoted('sites');
        $ownership = $tables->quoted('resource_site_ownership');
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (identifier VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci '
            . 'NOT NULL PRIMARY KEY) ENGINE = InnoDB',
            $sites,
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (resource_type VARCHAR(63) NOT NULL, resource_id VARCHAR(191) NOT NULL, '
            . 'site_identifier VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL, '
            . 'PRIMARY KEY (resource_type, resource_id)) ENGINE = InnoDB',
            $ownership,
        ));

        try {
            $manager = $database->createSchemaManager();
            self::assertSame(
                'utf8mb4_general_ci',
                $manager->introspectTableByUnquotedName($tables->raw('resource_site_ownership'))
                    ->getColumn('site_identifier')
                    ->getCollation(),
            );
            $database->insert($tables->raw('sites'), ['identifier' => 'default']);
            $database->insert($tables->raw('resource_site_ownership'), [
                'resource_type' => 'schedule',
                'resource_id' => 'a-schedule',
                'site_identifier' => 'default',
            ]);

            (new ResourceOwnershipPortabilityMigration($tables))->up($database);

            $repaired = $database->createSchemaManager()
                ->introspectTableByUnquotedName($tables->raw('resource_site_ownership'));
            self::assertSame('utf8mb4_unicode_ci', $repaired->getColumn('site_identifier')->getCollation());
            self::assertSame(
                [ResourceOwnershipPortabilityMigration::foreignKeyName($tables->raw('resource_site_ownership'))],
                array_keys($repaired->getForeignKeys()),
            );
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s o INNER JOIN %s site ON site.identifier = o.site_identifier '
                . 'WHERE o.resource_type = ?',
                $ownership,
                $sites,
            ), ['schedule'], [Types::STRING]));

            (new ResourceOwnershipPortabilityMigration($tables))->up($database);
            self::assertSame(
                'utf8mb4_unicode_ci',
                $database->createSchemaManager()
                    ->introspectTableByUnquotedName($tables->raw('resource_site_ownership'))
                    ->getColumn('site_identifier')
                    ->getCollation(),
                'Replaying the repair must leave the repaired schema exactly as it is.',
            );
        } finally {
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $ownership));
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $sites));
        }
    }

    /**
     * Proves the revision log carries the index the total history ordering is read from.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRevisionLogCarriesTheHistoryWindowIndex(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        $revisions = $tables->raw('business_record_revisions');
        $index = $database->createSchemaManager()
            ->introspectTableByUnquotedName($revisions)
            ->getIndex(BusinessRecordHistoryWindowMigration::indexName($revisions));

        self::assertSame(
            [
                'definition_id',
                'site_identifier',
                'record_identity_digest',
                'record_version',
                'revision_number',
                'record_id',
            ],
            array_map(
                static fn (string $column): string => trim($column, '"`[]'),
                $index->getColumns(),
            ),
        );
    }
}
