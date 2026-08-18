<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Automation;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\IdempotencyPurger;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;

/**
 * Doctrine implementation of `IdempotencyPurger` over the shared `idempotency` table.
 *
 * Reclaiming rows beside live traffic is the whole difficulty: a record whose retention window has
 * closed may since have been picked back up by a request. This purger therefore never deletes by
 * predicate alone. It reads a bounded, deterministically ordered batch of candidate identifiers, then
 * repeats the same expiry-and-ownership predicate inside the `DELETE`, so a row re-owned between the
 * two statements survives instead of being stripped of its replay protection — and the returned count
 * reports what actually went, not what was proposed. Both statements leave alone a finished record that
 * still holds an owner token and an in-progress record whose lock has not yet lapsed.
 *
 * @since  2.0.0
 */
final readonly class DoctrineIdempotencyPurger implements IdempotencyPurger
{
    /**
     * Wire the purger to the connection, the table map and the clock that fixes the cutoff.
     *
     * @param  Connection      $database  Connection the idempotency table is read from and deleted on.
     * @param  TableNames      $tables    Resolves the physical, quoted `idempotency` table name.
     * @param  ClockInterface  $clock     Supplies the instant expiry and lock lapse are judged against.
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
     * Delete one bounded batch of expired, unowned idempotency records.
     *
     * A single clock reading fixes the cutoff for both the candidate read and the delete, so no row can
     * fall on different sides of the boundary within one call. An empty candidate batch short-circuits
     * before any delete is issued.
     *
     * @param   int  $batchSize  Upper bound on how many records this call may remove; 1 to 10000.
     *
     * @return  int  Records actually deleted: short of the batch size once the backlog is drained, and
     *          short of the candidate count when a candidate was re-owned mid-call.
     *
     * @throws  InvalidArgumentException  When the batch size is below 1 or above 10000.
     * @throws  \RuntimeException  When a candidate row carries an identifier this purger cannot address.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the candidate read or the delete.
     *
     * @since   2.0.0
     */
    public function purgeExpired(int $batchSize = 1_000): int
    {
        $cutoff = $this->clock->now();
        $ids = $this->expiredCandidates($cutoff, $batchSize);
        if ($ids === []) {
            return 0;
        }

        return $this->deleteExpiredCandidates($ids, $cutoff);
    }

    /**
     * Read the identifiers of the next batch of records that are safe to delete.
     *
     * Candidacy is the expiry instant plus the ownership rule: a `completed` or `failed` record whose
     * owner token has been released, or an `in_progress` record whose lock is absent or has lapsed by
     * the cutoff. Ordering by `expires_at` then `id` makes the sweep walk the backlog oldest first and
     * pick the same rows again on a repeat run. It is public beyond `purgeExpired()` so an integration
     * test can assert which rows a purge would consider without deleting any of them.
     *
     * @param   DateTimeImmutable  $cutoff     Instant a record must have expired at or before, and by
     *          which an in-progress lock must have lapsed.
     * @param   int                $batchSize  Upper bound on identifiers returned; 1 to 10000.
     *
     * @return  list<string>  Candidate identifiers, oldest expiry first; empty when nothing qualifies.
     *
     * @throws  InvalidArgumentException  When the batch size is below 1 or above 10000.
     * @throws  \RuntimeException  When a returned identifier is not a non-empty string, meaning the table
     *          holds a row this purger cannot address.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the candidate read.
     *
     * @since   2.0.0
     */
    public function expiredCandidates(DateTimeImmutable $cutoff, int $batchSize): array
    {
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new InvalidArgumentException('Idempotency purge batch size must be between 1 and 10000.');
        }
        $ids = array_values($this->database->fetchFirstColumn(sprintf(
            "SELECT id FROM %s WHERE expires_at <= ? AND ((state IN ('completed', 'failed') "
            . "AND owner_token IS NULL) OR (state = 'in_progress' "
            . 'AND (locked_until IS NULL OR locked_until <= ?))) ORDER BY expires_at, id LIMIT %d',
            $this->tables->quoted('idempotency'),
            $batchSize,
        ), [$cutoff, $cutoff], [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE]));

        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                throw new \RuntimeException('An idempotency purge candidate has an invalid identifier.');
            }
        }
        /** @var list<non-empty-string> $ids */
        return $ids;
    }

    /**
     * Delete the named candidates, re-proving that each is still expired and still unowned.
     *
     * The predicate is repeated here rather than trusted from the read: between the two statements a
     * request may have taken a candidate back over, reopening it as `in_progress` under a fresh lock,
     * and such a row must survive the sweep. Identifiers that no longer match are simply not deleted,
     * which is why the count can fall short of the list supplied.
     *
     * @param   list<string>       $ids     Candidate identifiers from `expiredCandidates()`.
     * @param   DateTimeImmutable  $cutoff  Instant the expiry and lock-lapse checks are repeated against.
     *
     * @return  int  Records actually deleted; zero when the list is empty or every candidate was re-owned
     *          in the meantime.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the delete.
     *
     * @since   2.0.0
     */
    public function deleteExpiredCandidates(array $ids, DateTimeImmutable $cutoff): int
    {
        if ($ids === []) {
            return 0;
        }
        return (int) $this->database->executeStatement(sprintf(
            "DELETE FROM %s WHERE id IN (?) AND expires_at <= ? AND ((state IN ('completed', 'failed') "
            . "AND owner_token IS NULL) OR (state = 'in_progress' "
            . 'AND (locked_until IS NULL OR locked_until <= ?)))',
            $this->tables->quoted('idempotency'),
        ), [$ids, $cutoff, $cutoff], [
            ArrayParameterType::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
        ]);
    }
}
