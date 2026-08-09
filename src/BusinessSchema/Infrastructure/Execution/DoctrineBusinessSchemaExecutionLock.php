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

/**
 * Doctrine-backed mutual exclusion for schema execution, paired with a durable execution fence.
 *
 * Two things have to hold while a plan runs: no second executor may be applying schema changes, and
 * every journal write must be attributable to the run that made it. This adapter supplies both.
 * Exclusion is a session advisory lock — `GET_LOCK` on the MySQL family, `pg_try_advisory_lock` on
 * PostgreSQL — taken without waiting, so a second executor is refused rather than queued, and released
 * by the server if the connection dies. The fence is a single counter row bumped in its own
 * transaction under a `FOR UPDATE` read, so the number handed to the operation is strictly greater than
 * every number issued before it and a stale run's writes can be rejected on sight.
 *
 * The lock name is derived from the database name and the fence table rather than from the definition,
 * so deployments sharing a server do not block one another while all schema execution within one
 * deployment is serialised.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessSchemaExecutionLock implements BusinessSchemaExecutionLock
{
    /**
     * Bind the lock to the connection whose session will hold it.
     *
     * @param  Connection      $database  Connection carrying both the advisory lock and the fence row;
     *         the lock lives on this session, so the operation must run on the same connection.
     * @param  TableNames      $tables    Resolver for the physical name of the fence table.
     * @param  ClockInterface  $clock     Source of the timestamp stored beside each issued fence.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Run an operation while this process alone may apply business-schema changes.
     *
     * The lock is taken without waiting, so a concurrent executor is refused immediately instead of
     * blocking behind a migration that may run for hours. A fresh fence is allocated once the lock is
     * held and handed to the operation, which stamps it onto everything it journals. Whatever the
     * operation throws is re-thrown after a best-effort release, and a failure of that release is
     * swallowed so it cannot mask the original fault.
     *
     * @template T
     *
     * @param   string            $definitionId  Definition whose schema is being changed, as a UUID.
     * @param   callable(int): T  $operation     Work to run under the lock, receiving the fresh fence.
     *
     * @return  T  Whatever the operation returned.
     *
     * @throws  RuntimeException  When the definition ID is not a canonical UUID, the platform has no
     *          advisory-lock implementation, another executor already holds the lock, the fence row is
     *          unreadable, exhausted, or changed concurrently, or the lock could not be released.
     *
     * @since   2.0.0
     */
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

    /**
     * Work out the platform handle and the advisory-lock name this connection must use.
     *
     * The name is scoped to the current database and the fence table, not to the definition, which is
     * what makes schema execution exclusive across the whole deployment while leaving other
     * deployments on the same server free to proceed. The digest is truncated, which keeps the whole
     * name inside the 64 characters MySQL allows a lock to be named.
     *
     * @param   string  $definitionId  Definition being executed; the lock name does not vary with it.
     *
     * @return  array{AbstractPlatform, string}  The platform in use, and the lock name to pass to the
     *          acquire and release calls.
     *
     * @throws  RuntimeException  When the platform is neither MySQL-family nor PostgreSQL, or the
     *          server will not name its current database.
     *
     * @since   2.0.0
     */
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

    /**
     * Take the session advisory lock for this deployment, without waiting for it.
     *
     * @param   AbstractPlatform  $platform  Platform deciding which lock function is issued.
     * @param   string            $name      Lock name derived by `identity()`.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the server declines because another session already holds it.
     *
     * @since   2.0.0
     */
    private function acquire(AbstractPlatform $platform, string $name): void
    {
        $value = $platform instanceof AbstractMySQLPlatform
            ? $this->database->fetchOne('SELECT GET_LOCK(?, 0)', [$name])
            : $this->database->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$name]);
        if (!$this->accepted($value)) {
            throw new RuntimeException('Another executor is already applying this business schema.');
        }
    }

    /**
     * Hand the session advisory lock back to the server.
     *
     * The failure path releases with `$required` false: an operation that has already thrown must not
     * have its exception replaced by a complaint about the unlock.
     *
     * @param   AbstractPlatform  $platform  Platform deciding which unlock function is issued.
     * @param   string            $name      Lock name derived by `identity()`.
     * @param   bool              $required  Whether a refused release should be raised as a failure.
     *
     * @return  void
     *
     * @throws  RuntimeException  When $required is true and the server did not confirm the release.
     *
     * @since   2.0.0
     */
    private function release(AbstractPlatform $platform, string $name, bool $required): void
    {
        $value = $platform instanceof AbstractMySQLPlatform
            ? $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$name])
            : $this->database->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$name]);
        if ($required && !$this->accepted($value)) {
            throw new RuntimeException('The business-schema advisory lock could not be released.');
        }
    }

    /**
     * Claim the next execution fence and record when it was issued.
     *
     * The counter row is read `FOR UPDATE` and written back with its previous value in the `WHERE`
     * clause, so two runs that somehow reached this point together cannot both be served: the loser
     * updates no row and fails rather than proceeding on a fence someone else also holds. The write
     * commits before the operation starts, which is what makes the number durable across a crash.
     *
     * @return  int  The newly claimed fence, one greater than the value previously stored.
     *
     * @throws  RuntimeException  When the counter row is missing or does not hold a whole number, the
     *          counter has reached its ceiling, or the row changed between the read and the write.
     *
     * @since   2.0.0
     */
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

    /**
     * Decide whether a driver's answer to a lock call means the lock changed hands.
     *
     * MySQL answers with 1, while PostgreSQL's boolean reaches PHP as `t`, `true`, or a real bool
     * depending on the driver, so the accepted set is deliberately wider than one value. Anything
     * outside it counts as a refusal, including the SQL `NULL` an unlock returns for a lock that was
     * never held.
     *
     * @param   mixed  $value  Raw first column returned by the lock or unlock statement.
     *
     * @return  bool  True only for the driver spellings of a granted or released lock.
     *
     * @since   2.0.0
     */
    private function accepted(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 't', 'true'], true);
    }
}
