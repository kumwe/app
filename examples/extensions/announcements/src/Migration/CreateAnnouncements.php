<?php

declare(strict_types=1);

namespace KumweExample\Announcements\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Extension\Application\Migration\ExtensionMigration;
use Kumwe\CMS\Extension\Application\Migration\ExtensionTableNames;

final class CreateAnnouncements implements ExtensionMigration
{
    public function id(): string
    {
        return '20260804000000_create_announcements';
    }

    public function up(Connection $database, ExtensionTableNames $tables): void
    {
        $table = new Table($tables->raw('announcements'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('message', Types::TEXT);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $database->createSchemaManager()->createTable($table);
    }

    public function down(Connection $database, ExtensionTableNames $tables): void
    {
        $database->createSchemaManager()->dropTable($tables->raw('announcements'));
    }
}
