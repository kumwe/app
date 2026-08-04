<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

final readonly class DoctrineMigrationRepository implements MigrationRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function ensureLedger(): void
    {
        $schema = $this->database->createSchemaManager();
        $tableName = $this->tables->raw('schema_migrations');

        if ($schema->tablesExist([$tableName])) {
            return;
        }

        $table = new \Doctrine\DBAL\Schema\Table($tableName);
        $table->addColumn('version', Types::STRING, ['length' => 191]);
        $table->addColumn('checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('executed_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('execution_ms', Types::INTEGER, ['unsigned' => true]);
        $table->setPrimaryKey(['version']);
        $schema->createTable($table);
    }

    public function applied(): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT version, checksum FROM %s ORDER BY version',
            $this->tables->quoted('schema_migrations'),
        ));
        $applied = [];

        foreach ($rows as $row) {
            $version = $row['version'] ?? null;
            $checksum = $row['checksum'] ?? null;

            if (!is_string($version) || !is_string($checksum)) {
                throw new RuntimeException('The migration ledger contains invalid data.');
            }

            $applied[$version] = $checksum;
        }

        return $applied;
    }

    public function record(string $id, string $checksum, int $executionMilliseconds): void
    {
        $this->database->insert($this->tables->raw('schema_migrations'), [
            'version' => $id,
            'checksum' => $checksum,
            'executed_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'execution_ms' => $executionMilliseconds,
        ], [
            'version' => Types::STRING,
            'checksum' => Types::STRING,
            'executed_at' => Types::DATETIME_IMMUTABLE,
            'execution_ms' => Types::INTEGER,
        ]);
    }
}
