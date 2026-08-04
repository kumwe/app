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
                $this->database->quote($table),
            ))->loadResult();

            if (!in_array($exists, [true, 1, '1', 't'], true)) {
                return false;
            }

            $migration = $this->database->setQuery(sprintf(
                'SELECT version FROM %s.%s WHERE version = %s',
                $this->database->quoteName($this->schema),
                $this->database->quoteName('schema_migrations'),
                $this->database->quote($this->requiredMigration),
            ))->loadResult();

            return $migration === $this->requiredMigration;
        } catch (Throwable $exception) {
            $this->logger->warning('Readiness probe failed.', ['exception' => $exception]);

            return false;
        }
    }
}
