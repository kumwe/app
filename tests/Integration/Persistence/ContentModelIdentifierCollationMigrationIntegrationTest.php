<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ContentModelIdentifierCollationMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Exercises the forward content-model identifier repair against a real MySQL-family schema.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentModelIdentifierCollationMigration::class)]
final class ContentModelIdentifierCollationMigrationIntegrationTest extends TestCase
{
    /**
     * Repairs every version-table GUID join from a deliberately divergent collation and safely replays.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testVersionIdentifiersCopyAuthoritativeCollationsAndRemainRepeatable(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Textual GUID collation repair applies only to MySQL-family databases.');
        }

        $prefix = 'c' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10) . '_';
        $tables = new TableNames($database, $prefix);
        $names = [
            'workflows',
            'content_types',
            'content_entries',
            'workflow_definition_versions',
            'content_type_definition_versions',
        ];

        try {
            $this->createDivergentSchema($database, $tables);
            $this->seedMatchingContentModel($database, $tables);
            $migration = new ContentModelIdentifierCollationMigration($tables);
            $migration->up($database);
            $migration->up($database);

            $manager = $database->createSchemaManager();
            $workflows = $manager->introspectTableByUnquotedName($tables->raw('workflows'));
            $contentTypes = $manager->introspectTableByUnquotedName($tables->raw('content_types'));
            $contentEntries = $manager->introspectTableByUnquotedName($tables->raw('content_entries'));
            $workflowVersions = $manager->introspectTableByUnquotedName(
                $tables->raw('workflow_definition_versions'),
            );
            $contentTypeVersions = $manager->introspectTableByUnquotedName(
                $tables->raw('content_type_definition_versions'),
            );

            foreach (
                [
                    [$workflows->getColumn('id'), $contentEntries->getColumn('workflow_id')],
                    [$workflows->getColumn('id'), $workflowVersions->getColumn('workflow_id')],
                    [$contentTypes->getColumn('id'), $contentEntries->getColumn('content_type_id')],
                    [$contentTypes->getColumn('id'), $contentTypeVersions->getColumn('content_type_id')],
                    [$contentTypes->getColumn('workflow_id'), $contentTypeVersions->getColumn('workflow_id')],
                ] as [$expected, $actual]
            ) {
                self::assertNotNull($expected->getCharset());
                self::assertNotNull($expected->getCollation());
                self::assertSame($expected->getCharset(), $actual->getCharset());
                self::assertSame($expected->getCollation(), $actual->getCollation());
            }

            $workflowPrimary = $workflowVersions->getPrimaryKeyConstraint();
            $contentTypePrimary = $contentTypeVersions->getPrimaryKeyConstraint();
            self::assertInstanceOf(PrimaryKeyConstraint::class, $workflowPrimary);
            self::assertInstanceOf(PrimaryKeyConstraint::class, $contentTypePrimary);
            self::assertSame(
                ['workflow_id', 'version'],
                array_map(static fn (Name $name): string => $name->toString(), $workflowPrimary->getColumnNames()),
            );
            self::assertSame(
                ['content_type_id', 'version'],
                array_map(static fn (Name $name): string => $name->toString(), $contentTypePrimary->getColumnNames()),
            );

            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s e INNER JOIN %s wv '
                . 'ON wv.workflow_id = e.workflow_id AND wv.version = e.workflow_version',
                $tables->quoted('content_entries'),
                $tables->quoted('workflow_definition_versions'),
            )));
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s v INNER JOIN %s h ON h.id = v.content_type_id',
                $tables->quoted('content_type_definition_versions'),
                $tables->quoted('content_types'),
            )));
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s v INNER JOIN %s h ON h.id = v.workflow_id',
                $tables->quoted('workflow_definition_versions'),
                $tables->quoted('workflows'),
            )));
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s v INNER JOIN %s w ON w.id = v.workflow_id',
                $tables->quoted('content_type_definition_versions'),
                $tables->quoted('workflows'),
            )));
        } finally {
            foreach (array_reverse($names) as $name) {
                $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tables->quoted($name)));
            }
        }
    }

    /**
     * Create the five minimal content-model tables with version identifiers on a conflicting collation.
     *
     * @param   Connection  $database  MySQL-family integration connection.
     * @param   TableNames  $tables    Unique test table-name compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createDivergentSchema(Connection $database, TableNames $tables): void
    {
        $canonical = 'CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL';
        $divergent = 'CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (id %s, PRIMARY KEY (id)) ENGINE = InnoDB',
            $tables->quoted('workflows'),
            $canonical,
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (id %s, workflow_id %s, PRIMARY KEY (id)) ENGINE = InnoDB',
            $tables->quoted('content_types'),
            $canonical,
            $canonical,
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (id %s, content_type_id %s, workflow_id %s, workflow_version INTEGER NOT NULL, '
            . 'PRIMARY KEY (id)) ENGINE = InnoDB',
            $tables->quoted('content_entries'),
            $canonical,
            $canonical,
            $canonical,
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (workflow_id %s, version INTEGER NOT NULL, '
            . 'PRIMARY KEY (workflow_id, version)) ENGINE = InnoDB',
            $tables->quoted('workflow_definition_versions'),
            $divergent,
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (content_type_id %s, workflow_id %s, version INTEGER NOT NULL, '
            . 'PRIMARY KEY (content_type_id, version)) ENGINE = InnoDB',
            $tables->quoted('content_type_definition_versions'),
            $divergent,
            $divergent,
        ));
    }

    /**
     * Seed one complete content-model pin so every repaired equality join must preserve and return real data.
     *
     * @param   Connection  $database  MySQL-family integration connection.
     * @param   TableNames  $tables    Unique test table-name compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function seedMatchingContentModel(Connection $database, TableNames $tables): void
    {
        $workflowId = '00000000-0000-7000-8000-000000000001';
        $contentTypeId = '00000000-0000-7000-8000-000000000002';
        $entryId = '00000000-0000-7000-8000-000000000003';
        $database->insert($tables->raw('workflows'), ['id' => $workflowId]);
        $database->insert($tables->raw('content_types'), [
            'id' => $contentTypeId,
            'workflow_id' => $workflowId,
        ]);
        $database->insert($tables->raw('content_entries'), [
            'id' => $entryId,
            'content_type_id' => $contentTypeId,
            'workflow_id' => $workflowId,
            'workflow_version' => 1,
        ]);
        $database->insert($tables->raw('workflow_definition_versions'), [
            'workflow_id' => $workflowId,
            'version' => 1,
        ]);
        $database->insert($tables->raw('content_type_definition_versions'), [
            'content_type_id' => $contentTypeId,
            'workflow_id' => $workflowId,
            'version' => 1,
        ]);
    }
}
