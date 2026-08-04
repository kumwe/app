<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use RuntimeException;

final readonly class SchemaMigration implements Migration
{
    private SqlStatementSplitter $splitter;

    public function __construct(
        private string $migrationId,
        private string $schema,
        private string $sql,
        ?SqlStatementSplitter $splitter = null,
    ) {
        if (preg_match('/^[0-9]{14}_[a-z0-9_]+$/', $migrationId) !== 1) {
            throw new InvalidArgumentException('The schema migration ID is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }

        if (trim($sql) === '' || !str_contains($sql, '{{schema}}')) {
            throw new InvalidArgumentException('Schema migration SQL must contain the {{schema}} placeholder.');
        }

        $this->splitter = $splitter ?? new SqlStatementSplitter();
    }

    public static function fromFile(string $migrationId, string $schema, string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Migration SQL file "%s" is not readable.', $path));
        }

        $sql = file_get_contents($path);

        if (!is_string($sql)) {
            throw new RuntimeException(sprintf('Migration SQL file "%s" could not be read.', $path));
        }

        return new self($migrationId, $schema, $sql);
    }

    public function id(): string
    {
        return $this->migrationId;
    }

    public function checksum(): string
    {
        return hash('sha256', $this->migrationId . ':' . $this->schema . ':' . $this->sql);
    }

    public function up(DatabaseInterface $database): void
    {
        $sql = str_replace('{{schema}}', $database->quoteName($this->schema), $this->sql);

        foreach ($this->splitter->split($sql) as $statement) {
            if ($this->isTransactionBoundary($statement)) {
                continue;
            }

            $database->setQuery($statement)->execute();
        }
    }

    private function isTransactionBoundary(string $statement): bool
    {
        $withoutComments = preg_replace('/(?:\A|\R)\s*--[^\r\n]*/', '', $statement);
        $normalized = strtoupper(trim(is_string($withoutComments) ? $withoutComments : $statement));

        return $normalized === 'BEGIN' || $normalized === 'COMMIT';
    }
}
