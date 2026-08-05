<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Automation\JobExecutionClass;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/** Persists the site-versus-installation execution boundary for automation. */
final readonly class InstallationGlobalAutomationMigration implements RepeatableMigration
{
    public const ID = '20260805060000_installation_global_automation';

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
            throw new RuntimeException('The installation-global automation migration checksum could not be read.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        foreach (['jobs', 'schedules'] as $name) {
            $table = $after->getTable($this->tables->raw($name));
            if (!$table->hasColumn('execution_scope')) {
                $table->addColumn('execution_scope', Types::STRING, [
                    'length' => 16,
                    'default' => JobExecutionClass::Site->value,
                ]);
            }
        }
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }

        foreach (['jobs', 'schedules'] as $name) {
            $database->executeStatement(sprintf(
                'UPDATE %s SET execution_scope = ? WHERE job_type IN (?, ?)',
                $this->tables->quoted($name),
            ), [
                JobExecutionClass::Installation->value,
                'extensions.runtime.rebuild',
                'system.idempotency.purge',
            ]);
            $this->assertColumn(
                $manager->introspectTableByUnquotedName($this->tables->raw($name))->getColumn('execution_scope'),
            );
        }
    }

    private function assertColumn(Column $column): void
    {
        if (
            !$column->getType() instanceof StringType
            || $column->getLength() !== 16
            || $column->getNotnull() !== true
            || $column->getDefault() !== JobExecutionClass::Site->value
        ) {
            throw new RuntimeException('The persisted automation execution-scope column is divergent.');
        }
    }
}
