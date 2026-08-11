<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Creates the durable provenance ledger for evolving built-in demonstration datasets.
 *
 * The installation row records the one selected profile for each dataset and site. Asset rows bind
 * stable fixture keys to the resources the reconciler created, along with the exact fixture state it
 * last applied. Both tables are created independently when absent, so an interrupted implicit-commit
 * DDL sequence can safely replay the migration without replacing either completed table.
 *
 * @since  2.0.0
 */
final readonly class DemoProfileProvenanceMigration implements RepeatableMigration
{
    /**
     * Stable identity recorded in the core migration ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260811010000_demo_profile_provenance';

    /**
     * Bind portable table declarations to the installation's configured prefix.
     *
     * @param   TableNames  $tables  Validated compiler for physical table identifiers.
     *
     * @since   2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the migration's chronological ledger identity.
     *
     * @return  string  Stable identifier used to order and record this schema change.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind migration compatibility to the exact source bytes that declare these tables.
     *
     * @return  string  Lowercase SHA-256 digest namespaced by the migration identity.
     *
     * @throws  RuntimeException  When the migration source cannot be read for hashing.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The demo profile provenance migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Create the installation and asset ledgers in foreign-key dependency order.
     *
     * @param   Connection  $database  Open installation database receiving the portable schema.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        foreach ($this->tables() as $table) {
            $name = $table->getObjectName()->getUnqualifiedName()->getValue();
            if (!$manager->tablesExist([$name])) {
                $manager->createTable($table);
            }
        }
    }

    /**
     * Declare every provenance table in the only safe creation order.
     *
     * @return  list<Table>  Installation ledger followed by its dependent fixture-asset ledger.
     *
     * @since   2.0.0
     */
    private function tables(): array
    {
        return [
            $this->installations(),
            $this->assets(),
        ];
    }

    /**
     * Declare the selector and manifest checkpoint for each site dataset.
     *
     * The primary key deliberately excludes `selected_profile`: changing a selector updates the one
     * checkpoint for that dataset instead of permitting old and new selections to coexist silently.
     *
     * @return  Table  Portable installation-ledger declaration.
     *
     * @since   2.0.0
     */
    private function installations(): Table
    {
        $table = new Table($this->tables->raw('demo_profile_installations'));
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('dataset_key', Types::STRING, ['length' => 32]);
        $table->addColumn('selected_profile', Types::STRING, ['length' => 32]);
        $table->addColumn('manifest_version', Types::INTEGER);
        $table->addColumn('manifest_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('last_applied_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->primary($table, 'site_identifier', 'dataset_key');
        $table->addIndex(['status', 'updated_at'], 'idx_demo_profile_installation_status');

        return $table;
    }

    /**
     * Declare the fixture-to-resource ownership and last-applied state ledger.
     *
     * A fixture key is unique inside one site dataset regardless of selected profile. That lets the
     * reconciler detect a selector change and retire or replace the resource previously owned by the
     * same fixture slot without treating an operator-owned record as demo material.
     *
     * @return  Table  Portable asset-provenance declaration.
     *
     * @since   2.0.0
     */
    private function assets(): Table
    {
        $table = new Table($this->tables->raw('demo_profile_assets'));
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('dataset_key', Types::STRING, ['length' => 32]);
        $table->addColumn('fixture_key', Types::STRING, ['length' => 191]);
        $table->addColumn('resource_id', Types::STRING, ['length' => 191]);
        $table->addColumn('resource_type', Types::STRING, ['length' => 63]);
        $table->addColumn('last_applied_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('last_applied_version', Types::BIGINT);
        $table->addColumn('last_applied_state', Types::JSON);
        $table->addColumn('first_applied_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('last_applied_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'site_identifier', 'dataset_key', 'fixture_key');
        $table->addForeignKeyConstraint(
            $this->tables->raw('demo_profile_installations'),
            ['site_identifier', 'dataset_key'],
            ['site_identifier', 'dataset_key'],
            ['onDelete' => 'CASCADE'],
            'fk_demo_profile_asset_installation',
        );
        $table->addIndex(
            ['site_identifier', 'resource_type', 'resource_id'],
            'idx_demo_profile_asset_resource',
        );

        return $table;
    }

    /**
     * Attach a composite primary key through DBAL's current portable constraint builder.
     *
     * @param   Table             $table    Mutable table declaration receiving the constraint.
     * @param   non-empty-string  $first    First primary-key column in lookup order.
     * @param   non-empty-string  ...$rest  Remaining primary-key columns in lookup order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function primary(Table $table, string $first, string ...$rest): void
    {
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames($first, ...$rest)->create(),
        );
    }
}
