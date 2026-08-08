<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Infrastructure\Execution;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaExecutionLock;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/** Session advisory lock plus a durable monotonically increasing execution fence. */
final readonly class DoctrineBusinessSchemaExecutionLock implements BusinessSchemaExecutionLock
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
    ) {
    }

    public function synchronized(string $definitionId, callable $operation): mixed
    {
        if (!Uuid::isValid($definitionId)) {
            throw new RuntimeException('The schema execution lock definition ID is invalid.');
        }
        [$platform, $name] = $this->identity($definitionId);
        $this->acquire($platform, $name);
        try {
            $result = $operation($this->allocateFence());
        } catch (Throwable $exception) {
            try {
                $this->release($platform, $name, false);
            } catch (Throwable) {
                // The operation failure is authoritative; a lost session releases its server lock.
            }
            throw $exception;
        }
        $this->release($platform, $name, true);

        return $result;
    }

    /** @return array{AbstractPlatform, string} */
    private function identity(string $definitionId): array
    {
        $platform = $this->database->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $database = $this->database->fetchOne('SELECT DATABASE()');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $database = $this->database->fetchOne('SELECT current_database()');
        } else {
            throw new RuntimeException('The configured database has no schema-execution lock implementation.');
        }
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('The database identity for schema execution is unavailable.');
        }

        return [$platform, 'kumwe:business-schema:' . substr(hash(
            'sha256',
            $database . "\0" . $this->tables->raw('business_schema_fence'),
        ), 0, 38)];
    }

    private function acquire(AbstractPlatform $platform, string $name): void
    {
        $value = $platform instanceof AbstractMySQLPlatform
            ? $this->database->fetchOne('SELECT GET_LOCK(?, 0)', [$name])
            : $this->database->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$name]);
        if (!$this->accepted($value)) {
            throw new RuntimeException('Another executor is already applying this business schema.');
        }
    }

    private function release(AbstractPlatform $platform, string $name, bool $required): void
    {
        $value = $platform instanceof AbstractMySQLPlatform
            ? $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$name])
            : $this->database->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$name]);
        if ($required && !$this->accepted($value)) {
            throw new RuntimeException('The business-schema advisory lock could not be released.');
        }
    }

    private function allocateFence(): int
    {
        return $this->database->transactional(function (): int {
            $current = $this->database->fetchOne(sprintf(
                'SELECT fence FROM %s WHERE singleton_key = 1 FOR UPDATE',
                $this->tables->quoted('business_schema_fence'),
            ));
            if (!is_int($current) && (!is_string($current) || preg_match('/^[0-9]+$/D', $current) !== 1)) {
                throw new RuntimeException('The stored business-schema fence is invalid.');
            }
            $old = (int) $current;
            if ($old >= PHP_INT_MAX) {
                throw new RuntimeException('The business-schema execution fence is exhausted.');
            }
            $next = $old + 1;
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET fence = ?, updated_at = ? WHERE singleton_key = 1 AND fence = ?',
                $this->tables->quoted('business_schema_fence'),
            ), [$next, $this->clock->now(), $old], [
                Types::BIGINT,
                Types::DATETIME_IMMUTABLE,
                Types::BIGINT,
            ]);
            if ($affected !== 1) {
                throw new RuntimeException('The business-schema fence changed concurrently.');
            }

            return $next;
        });
    }

    private function accepted(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 't', 'true'], true);
    }
}
