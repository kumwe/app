<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

final readonly class DoctrineMigrationLock implements MigrationLock
{
    private const LOCK_NAME = 'core-migrations';

    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function synchronized(callable $operation): mixed
    {
        $this->ensureTable();
        $ownerToken = bin2hex(random_bytes(32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE lock_name = ? AND expires_at <= ?',
            $this->tables->quoted('migration_locks'),
        ), [self::LOCK_NAME, $now], [Types::STRING, Types::DATETIME_IMMUTABLE]);

        try {
            $this->database->insert($this->tables->raw('migration_locks'), [
                'lock_name' => self::LOCK_NAME,
                'owner_token' => $ownerToken,
                'acquired_at' => $now,
                'expires_at' => $now->modify('+30 minutes'),
            ], [
                'lock_name' => Types::STRING,
                'owner_token' => Types::STRING,
                'acquired_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException('Another process is already running database migrations.', 0, $exception);
        }

        try {
            return $operation();
        } finally {
            $this->database->delete($this->tables->raw('migration_locks'), [
                'lock_name' => self::LOCK_NAME,
                'owner_token' => $ownerToken,
            ]);
        }
    }

    private function ensureTable(): void
    {
        $schema = $this->database->createSchemaManager();
        $tableName = $this->tables->raw('migration_locks');

        if ($schema->tablesExist([$tableName])) {
            return;
        }

        $table = new \Doctrine\DBAL\Schema\Table($tableName);
        $table->addColumn('lock_name', Types::STRING, ['length' => 191]);
        $table->addColumn('owner_token', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('acquired_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['lock_name']);
        $schema->createTable($table);
    }
}
