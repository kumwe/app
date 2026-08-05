<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Operations\ExpiredMigrationLockRecovery;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;
use Throwable;

/** Holds a database-session advisory lock across implicit DDL and the final ledger write. */
final readonly class DoctrineMigrationLock implements MigrationLock, ExpiredMigrationLockRecovery
{
    private const LEGACY_NAME = 'core-migrations';
    private const V2_OWNER_PREFIX = 'advisory-v2:';

    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function synchronized(callable $operation): mixed
    {
        return $this->withAdvisoryLock(function () use ($operation): mixed {
            $this->ensureCompatibilityTable();
            $ownerToken = self::V2_OWNER_PREFIX . bin2hex(random_bytes(26));
            $this->acquireCompatibilityRow($ownerToken);

            try {
                $result = $operation();
            } catch (Throwable $exception) {
                try {
                    $this->releaseCompatibilityRow($ownerToken, false);
                } catch (Throwable) {
                    // Preserve the operation failure; the row blocks old binaries fail-closed after a crash.
                }

                throw $exception;
            }

            $this->releaseCompatibilityRow($ownerToken, true);

            return $result;
        });
    }

    public function recoverExpiredLegacyOwner(string $expectedOwnerToken): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedOwnerToken) !== 1) {
            throw new RuntimeException('The expected legacy migration owner token is invalid.');
        }

        $this->withAdvisoryLock(function () use ($expectedOwnerToken): void {
            $this->ensureCompatibilityTable();
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT owner_token, expires_at FROM %s WHERE lock_name = ?',
                $this->tables->quoted('migration_locks'),
            ), [self::LEGACY_NAME]);
            if ($row === false || ($row['owner_token'] ?? null) !== $expectedOwnerToken) {
                throw new RuntimeException('The expired legacy migration owner changed or no longer exists.');
            }
            $expiresAt = $row['expires_at'] ?? null;
            if (!is_string($expiresAt)) {
                throw new RuntimeException('The legacy migration lock expiry is invalid.');
            }
            try {
                $expiry = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
            } catch (Throwable $exception) {
                throw new RuntimeException('The legacy migration lock expiry is invalid.', 0, $exception);
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($expiry > $now) {
                throw new RuntimeException('The legacy migration owner has not expired.');
            }

            $deleted = $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE lock_name = ? AND owner_token = ? AND expires_at <= ?',
                $this->tables->quoted('migration_locks'),
            ), [self::LEGACY_NAME, $expectedOwnerToken, $now], [
                Types::STRING,
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
            ]);
            if ($deleted !== 1) {
                throw new RuntimeException('The expired legacy migration owner changed during recovery.');
            }
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withAdvisoryLock(callable $operation): mixed
    {
        [$platform, $lockName] = $this->advisoryIdentity();
        $this->acquireAdvisory($platform, $lockName);

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            try {
                $this->releaseAdvisory($platform, $lockName, false);
            } catch (Throwable) {
                // Preserve the operation failure; a lost session releases its advisory lock server-side.
            }

            throw $exception;
        }

        $this->releaseAdvisory($platform, $lockName, true);

        return $result;
    }

    /** @return array{0: AbstractPlatform, 1: string} */
    private function advisoryIdentity(): array
    {
        $platform = $this->database->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $databaseIdentity = $this->database->fetchOne('SELECT DATABASE()');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $databaseIdentity = $this->database->fetchOne('SELECT current_database()');
        } else {
            throw new RuntimeException('The configured database platform has no migration-lock implementation.');
        }
        if (!is_string($databaseIdentity) || $databaseIdentity === '') {
            throw new RuntimeException('The database identity for the migration lock is unavailable.');
        }

        return [$platform, 'kumwe:migrations:' . substr(hash(
            'sha256',
            $databaseIdentity . "\0" . $this->tables->raw('schema_migrations'),
        ), 0, 40)];
    }

    private function acquireAdvisory(AbstractPlatform $platform, string $lockName): void
    {
        $acquired = $platform instanceof AbstractMySQLPlatform
            ? $this->database->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName])
            : $this->database->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$lockName]);
        $accepted = $platform instanceof AbstractMySQLPlatform
            ? [1, '1', true]
            : [1, '1', true, 't', 'true'];
        if (!in_array($acquired, $accepted, true)) {
            throw new RuntimeException('Another process is already running database migrations.');
        }
    }

    private function releaseAdvisory(AbstractPlatform $platform, string $lockName, bool $required): void
    {
        $released = $platform instanceof AbstractMySQLPlatform
            ? $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName])
            : $this->database->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lockName]);
        $accepted = $platform instanceof AbstractMySQLPlatform
            ? [1, '1', true]
            : [1, '1', true, 't', 'true'];
        if ($required && !in_array($released, $accepted, true)) {
            throw new RuntimeException('The database migration advisory lock could not be released.');
        }
    }

    private function acquireCompatibilityRow(string $ownerToken): void
    {
        $table = $this->tables->raw('migration_locks');
        $existing = $this->database->fetchOne(sprintf(
            'SELECT owner_token FROM %s WHERE lock_name = ?',
            $this->tables->quoted('migration_locks'),
        ), [self::LEGACY_NAME]);
        if (is_string($existing) && str_starts_with($existing, self::V2_OWNER_PREFIX)) {
            // Holding the advisory lock proves that a marked v2 owner is no longer alive.
            $this->database->delete($table, [
                'lock_name' => self::LEGACY_NAME,
                'owner_token' => $existing,
            ]);
        } elseif ($existing !== false) {
            throw new RuntimeException(
                'A legacy migration owner is present; quiesce older binaries before retrying the upgrade.',
            );
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {
            $this->database->insert($table, [
                'lock_name' => self::LEGACY_NAME,
                'owner_token' => $ownerToken,
                'acquired_at' => $now,
                'expires_at' => new DateTimeImmutable('9999-12-31 23:59:59', new DateTimeZone('UTC')),
            ], [
                'lock_name' => Types::STRING,
                'owner_token' => Types::STRING,
                'acquired_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException('Another process is already running database migrations.', 0, $exception);
        }
    }

    private function releaseCompatibilityRow(string $ownerToken, bool $required): void
    {
        $deleted = $this->database->delete($this->tables->raw('migration_locks'), [
            'lock_name' => self::LEGACY_NAME,
            'owner_token' => $ownerToken,
        ]);
        if ($required && $deleted !== 1) {
            throw new RuntimeException('The database migration compatibility lock was lost.');
        }
    }

    /**
     * Dual-lock bridge for older builds that only understand this row.
     * A marked stale v2 row is removed only while the matching advisory namespace is held.
     */
    private function ensureCompatibilityTable(): void
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
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('lock_name')->create(),
        );
        $schema->createTable($table);
    }
}
