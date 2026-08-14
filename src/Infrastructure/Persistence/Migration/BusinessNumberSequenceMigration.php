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
 * Installs the counter table behind gapless business document numbers.
 *
 * A unique index can refuse a duplicate invoice number; it cannot produce the *next* one, and it cannot
 * make a run contiguous. `business_number_sequences` is the one row per counter that
 * `DoctrineBusinessNumberSequenceAllocator` takes `FOR UPDATE` and advances by one inside the record
 * command's own transaction, which is what makes a rolled-back create give its number back.
 *
 * The counter's identity is the natural tuple rather than a digest, so an operator reading the table can
 * see which site, definition, field, tenancy scope and calendar period a run belongs to, and the unique
 * index over that tuple is what settles the one race the row lock cannot: two commands creating the same
 * counter for the first time. Widths are chosen to keep that index inside the narrowest key the four
 * supported engines share — 371 characters, which is 1484 bytes at four bytes per character and well
 * inside InnoDB's 3072-byte limit.
 *
 * The table is created only when absent and nothing here is destructive, so an attempt interrupted on a
 * platform whose DDL commits implicitly may simply be replayed. Creating a table and its indexes needs
 * no privilege beyond the one the installation already holds over its own schema, so this runs unchanged
 * on MariaDB, MySQL with binary logging and no `SUPER`, PostgreSQL and SQLite.
 *
 * @since  2.0.0
 */
final readonly class BusinessNumberSequenceMigration implements RepeatableMigration
{
    /**
     * Stable migration identity recorded in the schema ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260814010000_business_number_sequence';

    /**
     * Bind the migration to the prefixed table map.
     *
     * @param  TableNames  $tables  Resolver applying the configured prefix to table names.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Name the identity recorded for this migration in the schema ledger.
     *
     * @return  string  The stable migration identifier.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Derive the ledger checksum from this file's bytes so any edit is detected.
     *
     * @return  string  Stable digest binding the recorded version to this exact implementation.
     *
     * @throws  RuntimeException  When the file digest cannot be calculated.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The business number sequence migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Create the counter table when it is absent, then prove it and its arbitrating index exist.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the table or its unique index is missing once the step has run.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the statement.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $name = $this->tables->raw('business_number_sequences');
        if (!$manager->tablesExist([$name])) {
            $manager->createTable($this->sequences($name));
        }
        $table = $manager->introspectTableByUnquotedName($name);
        $columns = ['id', 'site_identifier', 'definition_id', 'field_handle', 'scope_key', 'period_key', 'last_value'];
        foreach ($columns as $column) {
            if (!$table->hasColumn($column)) {
                throw new RuntimeException(sprintf(
                    'The business number sequence table is missing its %s column.',
                    $column,
                ));
            }
        }
        if (!$table->hasIndex($this->tables->raw('uniq_business_number_sequence'))) {
            throw new RuntimeException('The business number sequence table has no counter identity index.');
        }
    }

    /**
     * Declare the counter table portably across the four supported engines.
     *
     * @param   string  $name  Prefixed physical table name.
     *
     * @return  Table  Portable table declaration carrying the counter identity index.
     *
     * @since   2.0.0
     */
    private function sequences(string $name): Table
    {
        $table = new Table($name);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 64]);
        $table->addColumn('definition_id', Types::GUID);
        $table->addColumn('field_handle', Types::STRING, ['length' => 64]);
        $table->addColumn('scope_key', Types::STRING, ['length' => 191]);
        $table->addColumn('period_key', Types::STRING, ['length' => 16]);
        $table->addColumn('last_value', Types::BIGINT, ['default' => 0]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );
        $table->addUniqueIndex(
            ['site_identifier', 'definition_id', 'field_handle', 'scope_key', 'period_key'],
            $this->tables->raw('uniq_business_number_sequence'),
        );

        return $table;
    }
}
