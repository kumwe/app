<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds host-owned Content-to-Blueprint bindings and per-entry Studio composition overrides.
 *
 * The two stores remain beside Content rather than inside it: a Content definition and entry are
 * authoritative without composition, while these rows add optional host coordinates projected through
 * Studio's model port. Composite definition-version and entry foreign keys prevent bindings from
 * pointing at content that never existed. Constraint names are installation-unique at creation because
 * this migration runs after the schema-global name repairs and must not reintroduce their defect.
 *
 * @since  2.0.0
 */
final readonly class StudioContentProjectionMigration implements Migration
{
    /**
     * Stable append-only migration identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260824010000_studio_content_projection';

    /**
     * Bind the migration to prefix-aware table names.
     *
     * @param  TableNames  $tables  Installation table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the append-only identity recorded in the migration ledger.
     *
     * @return  string  Stable migration identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind applied migration history to the exact source bytes.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When the source digest cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The Studio Content projection migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Create both optional projection stores with portable constraints.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver refuses the generated schema statements.
     * @throws  RuntimeException  When MySQL-family parent character metadata is unavailable.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $mysqlFamily = $database->getDatabasePlatform() instanceof AbstractMySQLPlatform;

        $typeVersionsName = $this->tables->raw('content_type_definition_versions');
        $typeVersions = $after->getTable($typeVersionsName);
        $typeVersionSiteIndex = ConstraintNameIsolationMigration::isolatedName(
            $typeVersionsName,
            'uniq_studio_content_type_site_version',
        );
        if (!$typeVersions->hasIndex($typeVersionSiteIndex)) {
            $typeVersions->addUniqueIndex(
                ['site_identifier', 'content_type_id', 'version'],
                $typeVersionSiteIndex,
            );
        }

        $entriesName = $this->tables->raw('content_entries');
        $entries = $after->getTable($entriesName);
        $entrySiteIndex = ConstraintNameIsolationMigration::isolatedName(
            $entriesName,
            'uniq_studio_content_entry_site',
        );
        if (!$entries->hasIndex($entrySiteIndex)) {
            $entries->addUniqueIndex(['site_identifier', 'id'], $entrySiteIndex);
        }

        // Parent candidate keys must exist before SQLite rebuilds either child table with its composite
        // foreign key. Applying parent and child changes in one comparator plan lets SQLite validate the
        // new child against the pre-rebuild parent and report a foreign-key mismatch. The same explicit
        // phase is harmless on MariaDB/MySQL and PostgreSQL and makes the dependency order portable.
        $parentDifference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($parentDifference) as $statement) {
            $database->executeStatement($statement);
        }
        $before = $manager->introspectSchema();
        $after = clone $before;
        $typeVersions = $after->getTable($typeVersionsName);
        $entries = $after->getTable($entriesName);

        $bindingsName = $this->tables->raw('studio_content_blueprint_bindings');
        $bindings = $after->hasTable($bindingsName)
            ? $after->getTable($bindingsName)
            : $after->createTable($bindingsName);
        if (!$bindings->hasColumn('site_identifier')) {
            $bindings->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        }
        if (!$bindings->hasColumn('content_type_id')) {
            $bindings->addColumn('content_type_id', Types::GUID);
        }
        if (!$bindings->hasColumn('content_type_version')) {
            $bindings->addColumn('content_type_version', Types::INTEGER);
        }
        if (!$bindings->hasColumn('blueprint_id')) {
            $bindings->addColumn('blueprint_id', Types::STRING, ['length' => 240]);
        }
        if (!$bindings->hasColumn('blueprint_version')) {
            $bindings->addColumn('blueprint_version', Types::STRING, ['length' => 100]);
        }
        if (!$bindings->hasColumn('blueprint_revision')) {
            $bindings->addColumn('blueprint_revision', Types::STRING, ['length' => 200, 'notnull' => false]);
        }
        if (!$bindings->hasColumn('binding_revision')) {
            $bindings->addColumn('binding_revision', Types::INTEGER, ['default' => 1]);
        }
        if ($mysqlFamily) {
            self::copyCharacterDefinition(
                $typeVersions->getColumn('site_identifier'),
                $bindings->getColumn('site_identifier'),
            );
            self::copyCharacterDefinition(
                $typeVersions->getColumn('content_type_id'),
                $bindings->getColumn('content_type_id'),
            );
        }
        self::ensurePrimaryKey($bindings, ['content_type_id', 'content_type_version']);
        $bindingForeignKey = ConstraintNameIsolationMigration::isolatedName(
            $bindingsName,
            'fk_studio_binding_content_type',
        );
        if (!$bindings->hasForeignKey($bindingForeignKey)) {
            $bindings->addForeignKeyConstraint(
                $typeVersionsName,
                ['site_identifier', 'content_type_id', 'content_type_version'],
                ['site_identifier', 'content_type_id', 'version'],
                ['onDelete' => 'CASCADE'],
                $bindingForeignKey,
            );
        }

        $overridesName = $this->tables->raw('studio_entry_composition_overrides');
        $overrides = $after->hasTable($overridesName)
            ? $after->getTable($overridesName)
            : $after->createTable($overridesName);
        if (!$overrides->hasColumn('site_identifier')) {
            $overrides->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        }
        if (!$overrides->hasColumn('content_entry_id')) {
            $overrides->addColumn('content_entry_id', Types::GUID);
        }
        if (!$overrides->hasColumn('override_values')) {
            $overrides->addColumn('override_values', Types::JSON);
        }
        if (!$overrides->hasColumn('override_revision')) {
            $overrides->addColumn('override_revision', Types::INTEGER, ['default' => 1]);
        }
        if ($mysqlFamily) {
            self::copyCharacterDefinition(
                $entries->getColumn('site_identifier'),
                $overrides->getColumn('site_identifier'),
            );
            self::copyCharacterDefinition(
                $entries->getColumn('id'),
                $overrides->getColumn('content_entry_id'),
            );
        }
        self::ensurePrimaryKey($overrides, ['content_entry_id']);
        $overrideForeignKey = ConstraintNameIsolationMigration::isolatedName(
            $overridesName,
            'fk_studio_override_content_entry',
        );
        if (!$overrides->hasForeignKey($overrideForeignKey)) {
            $overrides->addForeignKeyConstraint(
                $entriesName,
                ['site_identifier', 'content_entry_id'],
                ['site_identifier', 'id'],
                ['onDelete' => 'CASCADE'],
                $overrideForeignKey,
            );
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Add a missing primary key or refuse a partial table carrying a different one.
     *
     * @param   Table                                    $table    Projection table being repaired.
     * @param   non-empty-list<non-empty-string>          $columns  Required primary-key columns in order.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an existing primary key is incompatible with the migration.
     *
     * @since   2.0.0
     */
    private static function ensurePrimaryKey(Table $table, array $columns): void
    {
        $primary = $table->getPrimaryKeyConstraint();
        if ($primary === null) {
            $first = $columns[0];
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setUnquotedColumnNames($first, ...array_slice($columns, 1))
                    ->create(),
            );

            return;
        }
        $actual = array_map(
            static fn (Name $name): string => $name->toString(),
            $primary->getColumnNames(),
        );
        if ($actual !== $columns) {
            throw new RuntimeException('A partial Studio projection table has an incompatible primary key.');
        }
    }

    /**
     * Copy the exact character definition required by a MySQL-family textual foreign key.
     *
     * @param   Column  $source  Authoritative parent column.
     * @param   Column  $target  New projection column referencing it.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the parent carries no complete character definition.
     *
     * @since   2.0.0
     */
    private static function copyCharacterDefinition(Column $source, Column $target): void
    {
        $charset = $source->getCharset();
        $collation = $source->getCollation();
        if ($charset === null || $collation === null) {
            throw new RuntimeException('A Studio projection parent column has no character definition to copy.');
        }

        $target->setPlatformOption('charset', $charset);
        $target->setPlatformOption('collation', $collation);
    }
}
