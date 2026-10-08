<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\Migration\RepeatableMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioAuthoringIdentityMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentAuthoringContextMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentAuthoringStartMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentProjectionMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioFieldBlockRevisionMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioItemCompositionMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Pins the forward migration that adds the item layout pointer and the handed-type fence (App ADR 0025).
 *
 * @since  2.0.0
 */
#[CoversClass(StudioItemCompositionMigration::class)]
#[UsesClass(StudioContentProjectionMigration::class)]
#[UsesClass(StudioContentAuthoringContextMigration::class)]
#[UsesClass(StudioAuthoringIdentityMigration::class)]
#[UsesClass(StudioContentAuthoringStartMigration::class)]
final class StudioItemCompositionMigrationTest extends TestCase
{
    /**
     * Entry whose stored override row must survive the migration unchanged.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be9c1';

    /**
     * The migration follows the previous append-only tail, is repeatable, and binds its ledger entry to its bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIdentityAndChecksumAreAppendOnly(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new StudioItemCompositionMigration(new TableNames($database, 'kumwe_'));
        $file = (new ReflectionClass($migration))->getFileName();
        self::assertIsString($file);

        self::assertInstanceOf(RepeatableMigration::class, $migration);
        self::assertSame('20261008120000_studio_item_composition', $migration->id());
        self::assertSame(StudioItemCompositionMigration::ID, $migration->id());
        self::assertGreaterThan(StudioFieldBlockRevisionMigration::ID, $migration->id());
        self::assertSame(
            hash('sha256', $migration->id() . ':' . hash_file('sha256', $file)),
            $migration->checksum(),
        );
        self::assertSame($migration->checksum(), $migration->checksum());
    }

    /**
     * Both columns are added nullable at their exact lengths, an existing override row keeps its values and
     * follows its type's layout, and a second run changes nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBothColumnsAreAddedNullableAndASecondRunChangesNothing(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        self::parents($database, $tables);
        $database->insert($tables->raw('content_entries'), ['site_identifier' => 'default', 'id' => self::ENTRY_ID]);
        $database->insert($tables->raw('studio_entry_composition_overrides'), [
            'site_identifier' => 'default',
            'content_entry_id' => self::ENTRY_ID,
            'override_values' => ['hero/main' => ['tone' => 'quiet']],
            'override_revision' => 6,
        ], ['override_values' => Types::JSON]);
        $stored = sprintf(
            'SELECT override_values, override_revision FROM %s',
            $tables->quoted('studio_entry_composition_overrides'),
        );
        $before = $database->fetchAssociative($stored);
        $migration = new StudioItemCompositionMigration($tables);

        $migration->up($database);
        $schema = $database->createSchemaManager()->introspectSchema();
        $pointer = $schema->getTable($tables->raw('studio_entry_composition_overrides'))
            ->getColumn('item_blueprint_revision');
        $digest = $schema->getTable($tables->raw('studio_content_authoring_contexts'))
            ->getColumn('handed_type_digest');
        $definitions = self::definitions($database);
        $migration->up($database);

        self::assertFalse($pointer->getNotnull());
        self::assertSame(200, $pointer->getLength());
        self::assertNull($pointer->getDefault());
        self::assertFalse($digest->getNotnull());
        self::assertSame(64, $digest->getLength());
        self::assertNull($digest->getDefault());
        self::assertSame($definitions, self::definitions($database), 'A second run changes nothing.');
        self::assertSame($before, $database->fetchAssociative($stored), 'The stored override row is kept.');
        self::assertNull($database->fetchOne(sprintf(
            'SELECT item_blueprint_revision FROM %s',
            $tables->quoted('studio_entry_composition_overrides'),
        )), 'An existing entry follows its type until it keeps its own layout.');
    }

    /**
     * A missing override table or authoring context table is refused before any column is added.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingParentTableIsRefused(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $migration = new StudioItemCompositionMigration($tables);
        try {
            $migration->up($database);
            self::fail('The migration ran without the entry composition override table.');
        } catch (RuntimeException $refused) {
            self::assertSame(
                'The Studio entry composition override table must exist before its layout.',
                $refused->getMessage(),
            );
        }

        self::contentParents($database, $tables);
        (new StudioContentProjectionMigration($tables))->up($database);
        try {
            $migration->up($database);
            self::fail('The migration ran without the authoring context table.');
        } catch (RuntimeException $refused) {
            self::assertSame(
                'The Studio Content authoring context table must exist before its type fence.',
                $refused->getMessage(),
            );
        }
        self::assertFalse(
            $database->createSchemaManager()
                ->introspectTableByUnquotedName($tables->raw('studio_entry_composition_overrides'))
                ->hasColumn('item_blueprint_revision'),
            'A refused run adds no column.',
        );
    }

    /**
     * Create both parent tables through their shipped migrations over minimal Content parents.
     *
     * @param   Connection  $database  In-memory database.
     * @param   TableNames  $tables    Prefix-aware table names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function parents(Connection $database, TableNames $tables): void
    {
        self::contentParents($database, $tables);
        (new StudioContentProjectionMigration($tables))->up($database);
        (new StudioContentAuthoringContextMigration($tables))->up($database);
        (new StudioAuthoringIdentityMigration($tables))->up($database);
        (new StudioContentAuthoringStartMigration($tables))->up($database);
    }

    /**
     * Create the minimal Content type-version and entry tables the projection migration references.
     *
     * @param   Connection  $database  In-memory database.
     * @param   TableNames  $tables    Prefix-aware table names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function contentParents(Connection $database, TableNames $tables): void
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
     * Read every stored schema definition in a stable order.
     *
     * @param   Connection  $database  In-memory database.
     *
     * @return  list<mixed>  Schema definitions by object name.
     *
     * @since   2.0.0
     */
    private static function definitions(Connection $database): array
    {
        return $database->fetchFirstColumn('SELECT sql FROM sqlite_master ORDER BY type, name');
    }
}
