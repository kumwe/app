<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Joomla\Database\DatabaseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ReadinessProbe
{
    public function __construct(
        private DatabaseInterface $database,
        private LoggerInterface $logger,
        private string $schema,
        private string $requiredMigration,
    ) {
    }

    public function ready(): bool
    {
        try {
            $this->database->connect();
            $table = $this->schema . '.schema_migrations';
            $exists = $this->database->setQuery(sprintf(
                'SELECT to_regclass(%s) IS NOT NULL',
                $this->quote($table),
            ))->loadResult();

            if (!in_array($exists, [true, 1, '1', 't'], true)) {
                return false;
            }

            $migration = $this->database->setQuery(sprintf(
                'SELECT version FROM %s.%s WHERE version = %s',
                $this->quoteName($this->schema),
                $this->quoteName('schema_migrations'),
                $this->quote($this->requiredMigration),
            ))->loadResult();

            return $migration === $this->requiredMigration;
        } catch (Throwable $exception) {
            $this->logger->warning('Readiness probe failed.', ['exception' => $exception]);

            return false;
        }
    }

    private function quote(string $value): string
    {
        $quoted = $this->database->quote($value);

        if (!is_string($quoted)) {
            throw new \RuntimeException('The database returned an invalid quoted value.');
        }

        return $quoted;
    }

    private function quoteName(string $identifier): string
    {
        $quoted = $this->database->quoteName($identifier);

        if (!is_string($quoted)) {
            throw new \RuntimeException('The database returned an invalid quoted identifier.');
        }

        return $quoted;
    }
}
