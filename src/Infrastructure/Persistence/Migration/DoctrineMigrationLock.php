<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Operations\ExpiredMigrationLockRecovery;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;
use Throwable;

/**
 * Holds a database-session advisory lock across implicit DDL and the final ledger write.
 *
 * Exclusion itself is a session advisory lock — `GET_LOCK` on the MySQL family,
 * `pg_try_advisory_lock` on PostgreSQL — taken without waiting, so a replica that loses the race
 * fails fast instead of blocking on startup. Because the server drops such a lock when the session
 * ends, it survives the implicit commits MySQL performs during DDL and needs no expiry to recover
 * from a crashed migrator, which is what an expiring row lock could never offer.
 *
 * A row in `migration_locks` is still claimed for the length of the run, because a pre-advisory
 * binary knows only that table and would otherwise migrate straight through a lock it cannot see.
 * That row is marked with `V2_OWNER_PREFIX` so a copy left behind by a crashed 2.x process can be
 * cleared automatically by the next run, while an unmarked row is treated as a live legacy owner and
 * refuses the run; `recoverExpiredLegacyOwner()` is the only way to remove one of those.
 *
 * @since  2.0.0
 */
final readonly class DoctrineMigrationLock implements MigrationLock, ExpiredMigrationLockRecovery
{
    /**
     * Row key the compatibility lock is claimed under, unchanged from the pre-advisory scheme.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LEGACY_NAME = 'core-migrations';
    /**
     * Marker distinguishing an owner token this implementation wrote from a genuine legacy one.
     *
     * Twelve characters, leaving 52 for the random suffix so a token still fills the fixed
     * 64-character column exactly. A prefixed row may be discarded once the advisory lock is held,
     * because holding it proves the process that wrote the row is gone. It also cannot be mistaken
     * for a legacy token, which is 64 hexadecimal digits and so never contains the prefix.
     *
     * @var    string
     * @since  2.0.0
     */
    private const V2_OWNER_PREFIX = 'advisory-v2:';

    /**
     * Bind the lock to the session that will hold it and the schema it guards.
     *
     * @param  Connection  $database  Connection whose session takes the advisory lock and writes the
     *         compatibility row; the lock lives and dies with that session.
     * @param  TableNames  $tables    Resolver for the physical `migration_locks` name and for the
     *         `schema_migrations` name the advisory lock is scoped to.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Run a migration pass while this session alone holds the advisory lock and the legacy row.
     *
     * The advisory lock is taken first, then the compatibility table is created when absent and a
     * marked row claimed inside it, so a binary watching only that table stays out too. On the
     * failure path the row is removed on a best-effort basis and the operation's own exception is
     * re-thrown unchanged; a row that outlives a crash never expires by itself and is instead cleared
     * by the next run that manages to take the advisory lock.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Migration work to run under exclusion.
     *
     * @return  T  Whatever the operation returned, handed back untouched.
     *
     * @throws  RuntimeException  When the platform cannot supply an advisory lock, another process
     *          already holds it, an unmarked legacy owner row is present, or either lock could not be
     *          released afterwards.
     *
     * @since   2.0.0
     */
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

    /**
     * Compare-and-delete one expired pre-advisory owner row so migrations can proceed again.
     *
     * This is the break-glass path behind `database:recover-lock`; it is never reached while running
     * migrations. The advisory lock is held across the check and the delete together, and the row
     * must still carry the exact token the operator read and an expiry already in the past, so a live
     * owner can never be unlocked from underneath the process holding it. Rows this implementation
     * wrote are out of reach here: their token carries `V2_OWNER_PREFIX` and fails the hex check.
     *
     * @param   string  $expectedOwnerToken  Owner token read from the stuck row, 64 lowercase hex
     *          digits, matched exactly before the row is removed.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the token is malformed, the row has changed hands or is gone,
     *          its stored expiry is unreadable or still in the future, or it changed during delete.
     *
     * @since   2.0.0
     */
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
     * Hold this deployment's advisory lock for the duration of one callback.
     *
     * Both the migration pass and the legacy-row recovery go through here, which is what stops a
     * recovery from deleting the row while a migration is relying on it. The failure path releases
     * without demanding confirmation, so a session that has already died cannot replace the
     * operation's exception with a complaint about the unlock.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Work to run while the lock is held.
     *
     * @return  T  Whatever the operation returned.
     *
     * @throws  RuntimeException  When the platform cannot supply an advisory lock, it is already held
     *          elsewhere, or the server did not confirm the release.
     *
     * @since   2.0.0
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

    /**
     * Work out the platform handle and the advisory-lock name this deployment must use.
     *
     * The name is derived from the connected database and the physical ledger table, so deployments
     * sharing a server — or one site installed twice under different table prefixes — migrate
     * independently while every replica of a single deployment serialises. The digest is truncated,
     * which keeps the whole name inside the 64 characters MySQL allows a lock to be named.
     *
     * @return  array{0: AbstractPlatform, 1: string}  The platform in use, and the lock name to pass
     *          to the acquire and release calls.
     *
     * @throws  RuntimeException  When the platform is neither MySQL-family nor PostgreSQL, or the
     *          server will not name its current database.
     *
     * @since   2.0.0
     */
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

    /**
     * Take the session advisory lock for this deployment, without waiting for it.
     *
     * The answer a driver gives differs by platform — MySQL replies with 1, PostgreSQL's boolean
     * reaches PHP as `t`, `true`, or a real bool — so the accepted set is deliberately wider than one
     * value, and anything outside it is read as a refusal.
     *
     * @param   AbstractPlatform  $platform  Platform deciding which lock function is issued.
     * @param   string            $lockName  Lock name derived by `advisoryIdentity()`.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the server declines because another session already holds it.
     *
     * @since   2.0.0
     */
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

    /**
     * Hand the session advisory lock back to the server.
     *
     * The failure path releases with `$required` false: an operation that has already thrown must not
     * have its exception replaced by a complaint about the unlock. A `NULL` answer, which a server
     * gives for a lock it does not consider held, counts as a refusal like any other value.
     *
     * @param   AbstractPlatform  $platform  Platform deciding which unlock function is issued.
     * @param   string            $lockName  Lock name derived by `advisoryIdentity()`.
     * @param   bool              $required  Whether a refused release should be raised as a failure.
     *
     * @return  void
     *
     * @throws  RuntimeException  When $required is true and the server did not confirm the release.
     *
     * @since   2.0.0
     */
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

    /**
     * Claim the legacy `core-migrations` row so a pre-advisory binary also sees the run as locked.
     *
     * Only reached with the advisory lock held, which is what makes removing a row marked with
     * `V2_OWNER_PREFIX` safe: whoever wrote it cannot still be running. An unmarked row belongs to a
     * binary that predates the advisory scheme, so it is left untouched and the run refused instead.
     * The claimed row is given an expiry far in the future, because expiry is how older binaries
     * decide a lock is abandoned and this one is released explicitly rather than left to lapse.
     *
     * @param   string  $ownerToken  Token identifying this holder, already carrying the prefix that
     *          marks it as written by the advisory-lock scheme.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an unmarked legacy owner is present, or another process
     *          inserted the row between the read and the insert.
     *
     * @since   2.0.0
     */
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

    /**
     * Delete the legacy row this run claimed, matched on both the lock name and the owner token.
     *
     * Matching on the token means a row some other holder has since claimed is never removed, and the
     * unsuccessful-path call passes `$required` false so a missing row cannot mask the real failure.
     *
     * @param   string  $ownerToken  Token the row must still carry for it to be removed.
     * @param   bool    $required    Whether a row that is already gone should be raised as a failure.
     *
     * @return  void
     *
     * @throws  RuntimeException  When $required is true and no matching row was there to delete.
     *
     * @since   2.0.0
     */
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
     * Create the `migration_locks` table when the database does not already have one.
     *
     * The compatibility row is the dual-lock bridge to older builds, which understand nothing else,
     * so the table has to exist before that row can be claimed on their behalf — including on a
     * database that has only ever been migrated by a build carrying the advisory lock. The shape is
     * the one those builds expect: the lock name as primary key, a fixed 64-character owner token,
     * and the acquired and expiry timestamps they compare against.
     *
     * @return  void
     *
     * @since   2.0.0
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
