<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/** Creates the portable immutable business-definition catalog without business-record tables. */
final readonly class BusinessDefinitionCatalogMigration implements Migration
{
    public const string ID = '20260807200000_business_definition_catalog';

    public function __construct(private TableNames $tables)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The business-definition catalog checksum could not be calculated.');
        }
        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        if (!$manager->tablesExist([$this->tables->raw('business_field_types')])) {
            $manager->createTable($this->fieldTypes());
        }
        if (!$manager->tablesExist([$this->tables->raw('business_definitions')])) {
            $manager->createTable($this->definitions());
        }
        if (!$manager->tablesExist([$this->tables->raw('business_definition_drafts')])) {
            $manager->createTable($this->drafts());
        }
        if (!$manager->tablesExist([$this->tables->raw('business_definition_versions')])) {
            $manager->createTable($this->versions());
        }
        if (!$manager->tablesExist([$this->tables->raw('business_definition_dependencies')])) {
            $manager->createTable($this->dependencies());
        }
    }

    private function fieldTypes(): Table
    {
        $table = new Table($this->tables->raw('business_field_types'));
        $table->addColumn('identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('owner_type', Types::STRING, ['length' => 16]);
        $table->addColumn('owner_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('source_version', Types::STRING, ['length' => 63]);
        $table->addColumn('active', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('canonical_payload', Types::JSON);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primaryKey($table, 'identifier');
        $table->addIndex(['owner_type', 'owner_identifier'], 'idx_business_field_type_owner');
        $table->addIndex(['active', 'identifier'], 'idx_business_field_type_active');

        return $table;
    }

    private function definitions(): Table
    {
        $table = new Table($this->tables->raw('business_definitions'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('handle', Types::STRING, ['length' => 191]);
        $table->addColumn('owner_type', Types::STRING, ['length' => 16]);
        $table->addColumn('owner_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('owner_active', Types::BOOLEAN, ['default' => true]);
        $table->addColumn('draft_revision', Types::INTEGER, ['default' => 0]);
        $table->addColumn('published_version', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('publication_state', Types::STRING, ['length' => 24, 'default' => 'draft']);
        $table->addColumn('created_by', Types::STRING, ['length' => 191]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primaryKey($table, 'id');
        $table->addUniqueIndex(['site_identifier', 'handle'], 'uniq_business_definition_handle');
        $table->addIndex(['owner_type', 'owner_identifier'], 'idx_business_definition_owner');
        $table->addIndex(['site_identifier', 'publication_state'], 'idx_business_definition_state');

        return $table;
    }

    private function drafts(): Table
    {
        $table = new Table($this->tables->raw('business_definition_drafts'));
        $table->addColumn('definition_id', Types::GUID);
        $table->addColumn('revision', Types::INTEGER);
        $table->addColumn('checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('canonical_payload', Types::JSON);
        $table->addColumn('dependency_graph', Types::JSON);
        $table->addColumn('updated_by', Types::STRING, ['length' => 191]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primaryKey($table, 'definition_id');
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_definitions'),
            ['definition_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_business_draft_definition',
        );

        return $table;
    }

    private function versions(): Table
    {
        $table = new Table($this->tables->raw('business_definition_versions'));
        $table->addColumn('definition_id', Types::GUID);
        $table->addColumn('version', Types::INTEGER);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('canonical_payload', Types::JSON);
        $table->addColumn('dependency_graph', Types::JSON);
        $table->addColumn('compatibility_plan', Types::JSON);
        $table->addColumn('published_by', Types::STRING, ['length' => 191]);
        $table->addColumn('published_at', Types::DATETIME_IMMUTABLE);
        $this->primaryKey($table, 'definition_id', 'version');
        $table->addUniqueIndex(['definition_id', 'checksum'], 'uniq_business_definition_checksum');
        $table->addIndex(['status', 'published_at'], 'idx_business_definition_version_state');
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_definitions'),
            ['definition_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_business_version_definition',
        );

        return $table;
    }

    private function dependencies(): Table
    {
        $table = new Table($this->tables->raw('business_definition_dependencies'));
        $table->addColumn('definition_id', Types::GUID);
        $table->addColumn('version', Types::INTEGER);
        $table->addColumn('dependency_kind', Types::STRING, ['length' => 24]);
        $table->addColumn('dependency_handle', Types::STRING, ['length' => 191]);
        $table->addColumn('owner_identifier', Types::STRING, ['length' => 191]);
        $this->primaryKey($table, 'definition_id', 'version', 'dependency_kind', 'dependency_handle');
        $table->addIndex(['dependency_kind', 'dependency_handle'], 'idx_business_dependency_lookup');
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_definition_versions'),
            ['definition_id', 'version'],
            ['definition_id', 'version'],
            ['onDelete' => 'CASCADE'],
            'fk_business_dependency_version',
        );

        return $table;
    }

    /**
     * @param non-empty-string $firstColumn
     * @param non-empty-string ...$columns
     */
    private function primaryKey(Table $table, string $firstColumn, string ...$columns): void
    {
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames($firstColumn, ...$columns)->create(),
        );
    }
}
