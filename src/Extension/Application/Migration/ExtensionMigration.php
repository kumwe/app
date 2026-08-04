<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Migration;

use Doctrine\DBAL\Connection;

/** A forward extension schema change with a compensating rollback. */
interface ExtensionMigration
{
    public function id(): string;

    public function up(Connection $database, ExtensionTableNames $tables): void;

    public function down(Connection $database, ExtensionTableNames $tables): void;
}
