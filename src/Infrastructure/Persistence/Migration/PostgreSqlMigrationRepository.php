<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Joomla\Database\DatabaseInterface;

final readonly class PostgreSqlMigrationRepository implements MigrationRepository
{
    public function __construct(private DatabaseInterface $database, private string $schema)
    {
    }

    public function ensureLedger(): void
    {
        $schema = $this->database->quoteName($this->schema);
        $this->database->setQuery(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $schema))->execute();
        $this->database->setQuery(sprintf(
            'CREATE TABLE IF NOT EXISTS %s.%s ('
            . 'version varchar(191) PRIMARY KEY, '
            . 'checksum char(64) NOT NULL, '
            . 'executed_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'execution_ms integer NOT NULL CHECK (execution_ms >= 0)'
            . ')',
            $schema,
            $this->database->quoteName('schema_migrations'),
        ))->execute();
    }

    public function applied(): array
    {
        $rows = $this->database->setQuery(sprintf(
            'SELECT version, checksum FROM %s.%s ORDER BY version',
            $this->database->quoteName($this->schema),
            $this->database->quoteName('schema_migrations'),
        ))->loadAssocList();
        $applied = [];

        foreach ($rows as $row) {
            $applied[(string) $row['version']] = (string) $row['checksum'];
        }

        return $applied;
    }

    public function record(string $id, string $checksum, int $executionMilliseconds): void
    {
        $this->database->setQuery(sprintf(
            'INSERT INTO %s.%s (version, checksum, execution_ms) VALUES (%s, %s, %d)',
            $this->database->quoteName($this->schema),
            $this->database->quoteName('schema_migrations'),
            $this->database->quote($id),
            $this->database->quote($checksum),
            $executionMilliseconds,
        ))->execute();
    }
}
