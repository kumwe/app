<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\GuidType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Kumwe\App\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\IndexNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentProjectionMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the append-only identity and portable optional stores used by the Studio Content model port.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioContentProjectionMigration::class)]
final class StudioContentProjectionMigrationTest extends TestCase
{
    /**
     * The migration follows the previous append-only tail and binds its ledger entry to exact source bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIdentityAndChecksumAreAppendOnly(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new StudioContentProjectionMigration(new TableNames($database, 'kumwe_'));

        self::assertSame('20260824010000_studio_content_projection', $migration->id());
        self::assertGreaterThan(IndexNameIsolationMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        self::assertSame($migration->checksum(), $migration->checksum());
    }

    /**
     * A fresh or replayed migration yields two closed stores linked to exact Content coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMigrationCreatesReplaySafePortableBindingAndOverrideStores(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $migration = new StudioContentProjectionMigration($tables);
        $this->createContentParents($database, $tables);
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (site_identifier VARCHAR(191) NOT NULL)',
            $tables->quoted('studio_content_blueprint_bindings'),
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (site_identifier VARCHAR(191) NOT NULL)',
            $tables->quoted('studio_entry_composition_overrides'),
        ));

        $migration->up($database);
        $migration->up($database);

        $manager = $database->createSchemaManager();
        $bindings = $manager->introspectTableByUnquotedName(
            $tables->raw('studio_content_blueprint_bindings'),
        );
        $overrides = $manager->introspectTableByUnquotedName(
            $tables->raw('studio_entry_composition_overrides'),
        );
        $typeVersions = $manager->introspectTableByUnquotedName(
            $tables->raw('content_type_definition_versions'),
        );
        $entries = $manager->introspectTableByUnquotedName($tables->raw('content_entries'));
        $typeVersionSiteIndex = ConstraintNameIsolationMigration::isolatedName(
            $tables->raw('content_type_definition_versions'),
            'uniq_studio_content_type_site_version',
        );
        $entrySiteIndex = ConstraintNameIsolationMigration::isolatedName(
            $tables->raw('content_entries'),
            'uniq_studio_content_entry_site',
        );
        self::assertTrue($typeVersions->hasIndex($typeVersionSiteIndex));
        self::assertTrue($typeVersions->getIndex($typeVersionSiteIndex)->isUnique());
        self::assertTrue($entries->hasIndex($entrySiteIndex));
        self::assertTrue($entries->getIndex($entrySiteIndex)->isUnique());
        self::assertSame(
            [
                'site_identifier',
                'content_type_id',
                'content_type_version',
                'blueprint_id',
                'blueprint_version',
                'blueprint_revision',
                'binding_revision',
            ],
            array_map(static fn (Column $column): string => $column->getName(), $bindings->getColumns()),
        );
        self::assertInstanceOf(StringType::class, $bindings->getColumn('site_identifier')->getType());
        self::assertSame(191, $bindings->getColumn('site_identifier')->getLength());
        self::assertContains(get_debug_type($bindings->getColumn('content_type_id')->getType()), [
            GuidType::class,
            StringType::class,
        ]);
        self::assertInstanceOf(IntegerType::class, $bindings->getColumn('content_type_version')->getType());
        self::assertSame(240, $bindings->getColumn('blueprint_id')->getLength());
        self::assertSame(100, $bindings->getColumn('blueprint_version')->getLength());
        self::assertSame(200, $bindings->getColumn('blueprint_revision')->getLength());
        self::assertFalse($bindings->getColumn('blueprint_revision')->getNotnull());
        self::assertSame('1', (string) $bindings->getColumn('binding_revision')->getDefault());
        self::assertPrimaryColumns($bindings->getPrimaryKeyConstraint(), [
            'content_type_id',
            'content_type_version',
        ]);
        self::assertForeignKey(
            array_values($bindings->getForeignKeys())[0] ?? null,
            ['site_identifier', 'content_type_id', 'content_type_version'],
            $tables->raw('content_type_definition_versions'),
            ['site_identifier', 'content_type_id', 'version'],
        );

        self::assertSame(
            ['site_identifier', 'content_entry_id', 'override_values', 'override_revision'],
            array_map(static fn (Column $column): string => $column->getName(), $overrides->getColumns()),
        );
        self::assertInstanceOf(StringType::class, $overrides->getColumn('site_identifier')->getType());
        self::assertContains(get_debug_type($overrides->getColumn('content_entry_id')->getType()), [
            GuidType::class,
            StringType::class,
        ]);
        self::assertContains(get_debug_type($overrides->getColumn('override_values')->getType()), [
            JsonType::class,
            TextType::class,
        ]);
        self::assertSame('1', (string) $overrides->getColumn('override_revision')->getDefault());
        self::assertPrimaryColumns($overrides->getPrimaryKeyConstraint(), ['content_entry_id']);
        self::assertForeignKey(
            array_values($overrides->getForeignKeys())[0] ?? null,
            ['site_identifier', 'content_entry_id'],
            $tables->raw('content_entries'),
            ['site_identifier', 'id'],
        );
    }

    /**
     * Create the authoritative Content parents with their tenant ownership columns.
     *
     * @param   \Doctrine\DBAL\Connection  $database  SQLite test connection.
     * @param   TableNames                  $tables    Prefix-aware table-name compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createContentParents(\Doctrine\DBAL\Connection $database, TableNames $tables): void
    {
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (site_identifier VARCHAR(191) NOT NULL, content_type_id VARCHAR(36) NOT NULL, '
                . 'version INTEGER NOT NULL, PRIMARY KEY (content_type_id, version))',
            $tables->quoted('content_type_definition_versions'),
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (site_identifier VARCHAR(191) NOT NULL, id VARCHAR(36) NOT NULL PRIMARY KEY)',
            $tables->quoted('content_entries'),
        ));
    }

    /**
     * Assert one primary constraint carries the expected unquoted columns in order.
     *
     * @param   ?PrimaryKeyConstraint  $primary  Introspected primary-key constraint.
     * @param   list<string>           $columns  Expected unquoted columns.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertPrimaryColumns(?PrimaryKeyConstraint $primary, array $columns): void
    {
        self::assertNotNull($primary);
        self::assertSame($columns, array_map(
            static fn (UnqualifiedName $name): string => $name->getIdentifier()->getValue(),
            $primary->getColumnNames(),
        ));
    }

    /**
     * Assert one foreign key preserves both sides of the immutable Content coordinate and cascades deletion.
     *
     * @param   mixed         $foreignKey        Candidate introspected constraint.
     * @param   list<string>  $localColumns      Referencing projection columns.
     * @param   string        $referencedTable   Expected prefixed Content table.
     * @param   list<string>  $referencedColumns  Referenced Content coordinate columns.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertForeignKey(
        mixed $foreignKey,
        array $localColumns,
        string $referencedTable,
        array $referencedColumns,
    ): void {
        self::assertInstanceOf(ForeignKeyConstraint::class, $foreignKey);
        self::assertSame($localColumns, array_map(
            static fn (UnqualifiedName $name): string => $name->getIdentifier()->getValue(),
            $foreignKey->getReferencingColumnNames(),
        ));
        self::assertSame(
            $referencedTable,
            $foreignKey->getReferencedTableName()->getUnqualifiedName()->getValue(),
        );
        self::assertSame($referencedColumns, array_map(
            static fn (UnqualifiedName $name): string => $name->getIdentifier()->getValue(),
            $foreignKey->getReferencedColumnNames(),
        ));
        self::assertSame(ReferentialAction::CASCADE, $foreignKey->getOnDeleteAction());
    }
}
