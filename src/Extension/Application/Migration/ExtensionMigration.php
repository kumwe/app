<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Migration;

use Doctrine\DBAL\Connection;

/**
 * A forward extension schema change. up() MUST be idempotent and resume safely after
 * implicit-commit DDL; the durable install saga retries it until the migration ledger commits.
 */
interface ExtensionMigration
{
    public function id(): string;

    public function up(Connection $database, ExtensionTableNames $tables): void;

    public function down(Connection $database, ExtensionTableNames $tables): void;
}
