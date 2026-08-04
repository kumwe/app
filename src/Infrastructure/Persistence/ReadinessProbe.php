<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ReadinessProbe
{
    public function __construct(
        private Connection $database,
        private LoggerInterface $logger,
        private TableNames $tables,
        private string $requiredMigration,
        private ?RedisRuntime $redis = null,
    ) {
    }

    public function ready(): bool
    {
        try {
            $this->database->connect();

            if ($this->redis !== null && !$this->redis->ready()) {
                return false;
            }

            if (!$this->database->createSchemaManager()->tablesExist([
                $this->tables->raw('schema_migrations'),
            ])) {
                return false;
            }

            $migration = $this->database->fetchOne(sprintf(
                'SELECT version FROM %s WHERE version = ?',
                $this->tables->quoted('schema_migrations'),
            ), [$this->requiredMigration]);

            return $migration === $this->requiredMigration;
        } catch (Throwable $exception) {
            $this->logger->warning('Readiness probe failed.', ['exception' => $exception]);

            return false;
        }
    }

}
