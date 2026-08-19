<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/** Adds portable ownership metadata for extension-contributed capabilities. */
final readonly class ExtensionContributionCatalogMigration implements Migration
{
    public const string ID = '20260807190000_extension_contribution_catalog';

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
            throw new RuntimeException('The extension contribution catalog checksum could not be calculated.');
        }
        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $name = $this->tables->raw('extension_contribution_capabilities');
        if ($manager->tablesExist([$name])) {
            return;
        }
        $table = new Table($name);
        $table->addColumn('extension_id', Types::GUID);
        $table->addColumn('capability_code', Types::STRING, ['length' => 191]);
        $table->addColumn('description', Types::STRING, ['length' => 500]);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('capability_code')->create(),
        );
        $table->addIndex(['extension_id'], 'idx_extension_contribution_capability_owner');
        $table->addForeignKeyConstraint(
            $this->tables->raw('extensions'),
            ['extension_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_extension_contribution_capability_owner',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('capabilities'),
            ['capability_code'],
            ['code'],
            ['onDelete' => 'CASCADE'],
            'fk_extension_contribution_capability_definition',
        );
        $manager->createTable($table);
    }
}
