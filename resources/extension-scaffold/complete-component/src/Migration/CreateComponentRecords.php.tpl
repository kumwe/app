<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Extension\Application\Migration\ExtensionMigration;
use Kumwe\App\Extension\Application\Migration\ExtensionTableNames;

/**
 * Creates the component-owned storage used for integration exercises.
 *
 * @since  2.0.0
 */
final class CreateComponentRecords implements ExtensionMigration
{
    /**
     * Return the stable migration ledger identifier.
     *
     * @return  string  Timestamped component migration identifier.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return '20260810000000_create_component_records';
    }

    /**
     * Create the namespaced component-record table.
     *
     * @param   Connection           $database  Transactional extension migration connection.
     * @param   ExtensionTableNames  $tables    Owner-bound physical table-name allocator.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database, ExtensionTableNames $tables): void
    {
        $name = $tables->raw('component_records');
        $schema = $database->createSchemaManager();
        if ($schema->tablesExist([$name])) {
            return;
        }
        $table = new Table($name);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $schema->createTable($table);
    }

    /**
     * Drop only the table allocated to this component.
     *
     * @param   Connection           $database  Transactional extension migration connection.
     * @param   ExtensionTableNames  $tables    Owner-bound physical table-name allocator.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function down(Connection $database, ExtensionTableNames $tables): void
    {
        $name = $tables->raw('component_records');
        $schema = $database->createSchemaManager();
        if ($schema->tablesExist([$name])) {
            $schema->dropTable($name);
        }
    }
}
