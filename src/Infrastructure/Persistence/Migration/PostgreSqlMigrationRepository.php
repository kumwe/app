<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Joomla\Database\DatabaseInterface;
use RuntimeException;

final readonly class PostgreSqlMigrationRepository implements MigrationRepository
{
    public function __construct(private DatabaseInterface $database, private string $schema)
    {
    }

    public function ensureLedger(): void
    {
        $schema = $this->quoteName($this->schema);
        $this->database->setQuery(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $schema))->execute();
        $this->database->setQuery(sprintf(
            'CREATE TABLE IF NOT EXISTS %s.%s ('
            . 'version varchar(191) PRIMARY KEY, '
            . 'checksum char(64) NOT NULL, '
            . 'executed_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'execution_ms integer NOT NULL CHECK (execution_ms >= 0)'
            . ')',
            $schema,
            $this->quoteName('schema_migrations'),
        ))->execute();
    }

    public function applied(): array
    {
        $rows = $this->database->setQuery(sprintf(
            'SELECT version, checksum FROM %s.%s ORDER BY version',
            $this->quoteName($this->schema),
            $this->quoteName('schema_migrations'),
        ))->loadAssocList();

        if (!is_array($rows)) {
            throw new RuntimeException('The migration ledger query returned an invalid row set.');
        }

        $applied = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('The migration ledger contains an invalid row.');
            }

            $version = $row['version'] ?? null;
            $checksum = $row['checksum'] ?? null;

            if (!is_string($version) || !is_string($checksum)) {
                throw new RuntimeException('The migration ledger contains invalid version or checksum values.');
            }

            $applied[$version] = $checksum;
        }

        return $applied;
    }

    public function record(string $id, string $checksum, int $executionMilliseconds): void
    {
        $this->database->setQuery(sprintf(
            'INSERT INTO %s.%s (version, checksum, execution_ms) VALUES (%s, %s, %d)',
            $this->quoteName($this->schema),
            $this->quoteName('schema_migrations'),
            $this->quote($id),
            $this->quote($checksum),
            $executionMilliseconds,
        ))->execute();
    }

    private function quote(string $value): string
    {
        $quoted = $this->database->quote($value);

        if (!is_string($quoted)) {
            throw new RuntimeException('The database returned an invalid quoted value.');
        }

        return $quoted;
    }

    private function quoteName(string $identifier): string
    {
        $quoted = $this->database->quoteName($identifier);

        if (!is_string($quoted)) {
            throw new RuntimeException('The database returned an invalid quoted identifier.');
        }

        return $quoted;
    }
}
