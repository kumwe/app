<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds the portable optimistic store for KIS presentation preferences.
 *
 * @since  2.0.0
 */
final readonly class InterfacePresentationPreferenceMigration implements Migration
{
    /**
     * Stable chronological migration identity written to the core migration ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260811140000_interface_presentation_preferences';

    /**
     * Bind physical table creation to the configured installation prefix.
     *
     * @param  TableNames  $tables  Resolver producing the portable physical table name.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the immutable migration ledger identity.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind the ledger checksum to this migration's exact implementation bytes.
     *
     * @return  string  SHA-256 migration identity and source digest.
     *
     * @throws  RuntimeException  When the runtime cannot hash the migration file.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The interface preference migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Create the KIS preference table once with database-neutral null identity semantics.
     *
     * @param   Connection  $database  DBAL connection whose schema manager owns the table.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the database rejects schema inspection or creation.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $name = $this->tables->raw('interface_presentation_preferences');
        if ($manager->tablesExist([$name])) {
            return;
        }
        $table = new Table($name);
        $table->addColumn('schema_version', Types::SMALLINT);
        $table->addColumn('standard_version', Types::STRING, ['length' => 16]);
        $table->addColumn('surface_id', Types::STRING, ['length' => 191]);
        $table->addColumn('owner_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('scope', Types::STRING, ['length' => 32]);
        $table->addColumn('scope_key', Types::STRING, ['length' => 191]);
        $table->addColumn('scope_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('slot', Types::STRING, ['length' => 64]);
        $table->addColumn('preference_value', Types::JSON);
        $table->addColumn('version', Types::BIGINT);
        $table->addColumn('updated_by', Types::STRING, ['length' => 191]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames('surface_id', 'slot', 'scope', 'scope_key')
                ->create(),
        );
        $table->addIndex(
            ['surface_id', 'slot'],
            'idx_interface_preference_' . substr(hash('sha256', $name), 0, 16),
        );
        $manager->createTable($table);
    }
}
