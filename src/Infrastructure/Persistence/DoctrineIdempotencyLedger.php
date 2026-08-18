<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Idempotency\IdempotencyLedger;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Doctrine implementation of `IdempotencyLedger` over the shared `idempotency` table.
 *
 * This is where the ledger's promises become SQL. The reservation is an insert, so the unique index
 * over subject, operation and key arbitrates between simultaneous first attempts rather than a
 * read-then-write that could interleave. Every takeover repeats its own precondition inside the
 * `UPDATE` — expiry, the failed state, or the lapsed lock — so the affected-row count is a truthful
 * answer to "did I win", and the completion and release writes carry the whole claim in their
 * predicates for the same reason. Drivers disagree on whether an affected-row count arrives as an int
 * or a decimal string, so ownership is judged as text once, here, instead of in every caller.
 *
 * @since  2.0.0
 */
final readonly class DoctrineIdempotencyLedger implements IdempotencyLedger
{
    /**
     * How long a reservation stays owned by the request that took it, in seconds.
     *
     * The window is generous because the mutations behind it can be slow. A request that died mid-flight
     * blocks its key for at most this long, after which another attempt may take the record over.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int PROCESSING_LEASE_SECONDS = 900;

    /**
     * How long a record stays replayable after it is written, in seconds.
     *
     * Once the window closes the record no longer answers a repeat: the key may be claimed afresh by the
     * next request that presents it, and `DoctrineIdempotencyPurger` is free to delete the row.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int RETENTION_SECONDS = 86_400;

    /**
     * Wire the ledger to the connection, the table map and the clock its leases are dated from.
     *
     * @param  Connection      $database  Connection the idempotency ledger is read and written on.
     * @param  TableNames      $tables    Resolves the physical `idempotency` table name.
     * @param  ClockInterface  $clock     Supplies the instants leases, retention and expiry are measured
     *         from.
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
     * Reserve a key by inserting an in-progress record, letting the unique index decide a collision.
     *
     * @param   string  $subject                   Principal the record is keyed against.
     * @param   string  $operation                 Method and path pair the key is scoped to.
     * @param   string  $key                       The client's validated `Idempotency-Key`.
     * @param   string  $requestDigest             Digest of the request the key is being spent on.
     * @param   string  $authorizationFingerprint  Credential and site fingerprint stored with the claim.
     * @param   string  $ownerToken                Random token marking the reservation as this request's.
     *
     * @return  bool  True when the insert landed; false when the unique index refused it.
     *
     * @since   2.0.0
     */
    public function reserve(
        string $subject,
        string $operation,
        string $key,
        string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
    ): bool {
        $now = $this->clock->now();
        try {
            $this->database->insert($this->tables->raw('idempotency'), [
                'id' => Uuid::uuid7()->toString(),
                'idempotency_key' => $key,
                'subject' => $subject,
                'operation' => $operation,
                'request_digest' => $requestDigest,
                'authorization_fingerprint' => $authorizationFingerprint,
                'state' => 'in_progress',
                'owner_token' => $ownerToken,
                'locked_until' => $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
                'lease_owner' => $ownerToken,
                'lease_expires_at' => $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
                'result_status' => null,
                'result_body' => null,
                'result_headers' => null,
                'result_body_digest' => null,
                'created_at' => $now,
                'completed_at' => null,
                'expires_at' => $now->modify('+' . self::RETENTION_SECONDS . ' seconds'),
            ], [
                'locked_until' => Types::DATETIME_IMMUTABLE,
                'lease_expires_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Read the stored record a failed reservation collided with.
     *
     * @param   string  $subject    Principal the record is keyed against.
     * @param   string  $operation  Method and path pair the key is scoped to.
     * @param   string  $key        The client's `Idempotency-Key`.
     *
     * @return  ?array<string, mixed>  The stored record keyed by column name, or null when it vanished
     *          between the collision and this read.
     *
     * @since   2.0.0
     */
    public function find(string $subject, string $operation, string $key): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT id, request_digest, authorization_fingerprint, state, owner_token, locked_until, '
            . 'result_status, result_body, result_body_digest, result_headers, expires_at FROM %s '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key]);

        return $row === false ? null : $row;
    }

    /**
     * Claim a record whose retention window has already closed.
     *
     * The expiry test is repeated inside the update rather than trusted from the read, so a record
     * another request revived in between is left alone and this one loses the race cleanly.
     *
     * @param   string  $id                        Identifier of the record being taken over.
     * @param   string  $requestDigest             Digest of this request, replacing the expired one.
     * @param   string  $authorizationFingerprint  Fingerprint stored with the new reservation.
     * @param   string  $ownerToken                Token proving the new reservation is this request's.
     *
     * @return  bool  True when this request now owns the record; false when it was revived concurrently.
     *
     * @since   2.0.0
     */
    public function takeOverExpired(
        string $id,
        string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
    ): bool {
        $now = $this->clock->now();

        return $this->reset(
            $id,
            $requestDigest,
            $authorizationFingerprint,
            $ownerToken,
            $now,
            'expires_at <= ?',
            [$now],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Claim a record left behind by an attempt that ended in failure.
     *
     * The `failed` state is re-tested inside the update, so a record another retry has already picked
     * up is not stolen from it.
     *
     * @param   string  $id                        Identifier of the record being retried.
     * @param   string  $requestDigest             Digest of this request, equal to the stored one.
     * @param   string  $authorizationFingerprint  Fingerprint stored with the new reservation.
     * @param   string  $ownerToken                Token proving the new reservation is this request's.
     *
     * @return  bool  True when this request now owns the record; false when another retry claimed it
     *          first.
     *
     * @since   2.0.0
     */
    public function takeOverFailed(
        string $id,
        string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
    ): bool {
        return $this->reset(
            $id,
            $requestDigest,
            $authorizationFingerprint,
            $ownerToken,
            $this->clock->now(),
            "state = 'failed'",
            [],
            [],
        );
    }

    /**
     * Claim an in-progress record whose processing lease has run out.
     *
     * The lease test is repeated inside the update, so only one of several waiting attempts wins.
     *
     * @param   string  $id                        Identifier of the record being taken over.
     * @param   string  $authorizationFingerprint  Fingerprint stored with the new reservation.
     * @param   string  $ownerToken                Token proving the new reservation is this request's.
     *
     * @return  bool  True when this request now owns the record; false when the lease was still held or
     *          another attempt claimed it first.
     *
     * @since   2.0.0
     */
    public function takeOverStale(string $id, string $authorizationFingerprint, string $ownerToken): bool
    {
        $now = $this->clock->now();

        return $this->reset(
            $id,
            null,
            $authorizationFingerprint,
            $ownerToken,
            $now,
            "state = 'in_progress' AND (locked_until IS NULL OR locked_until <= ?)",
            [$now],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Settle the record as completed and store the result future repeats are answered with.
     *
     * The predicate re-states the whole claim — same owner token, still `in_progress`, lease not yet
     * lapsed — so a lost race answers false instead of overwriting a record another request now owns.
     *
     * @param   string                 $subject     Principal the record is keyed against.
     * @param   string                 $operation   Method and path pair the key is scoped to.
     * @param   string                 $key         The client's `Idempotency-Key`.
     * @param   string                 $ownerToken  Token this request stored when it claimed the record.
     * @param   int                    $status      HTTP status a replay of this key must reproduce.
     * @param   string                 $body        Response body to store and replay verbatim.
     * @param   array<string, string>  $headers     Header lines a replay must reproduce, keyed by name.
     *
     * @return  bool  True when exactly the one record this request owns was settled.
     *
     * @since   2.0.0
     */
    public function complete(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        int $status,
        string $body,
        array $headers,
    ): bool {
        $now = $this->clock->now();
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET state = 'completed', owner_token = NULL, locked_until = NULL, "
            . 'lease_owner = NULL, lease_expires_at = NULL, result_status = ?, result_body = ?, '
            . 'result_body_digest = ?, result_headers = ?, completed_at = ? '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ? AND state = '
            . "'in_progress' AND owner_token = ? AND locked_until > ?",
            $this->tables->quoted('idempotency'),
        ), [
            $status, $body, hash('sha256', $body), $headers, $now,
            $subject, $operation, $key, $ownerToken, $now,
        ], [
            Types::INTEGER, Types::TEXT, Types::STRING, Types::JSON, Types::DATETIME_IMMUTABLE,
            Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]);

        return $this->exactlyOne($affected);
    }

    /**
     * Give the key back after an attempt that did not settle, deleting only this request's reservation.
     *
     * @param   string  $subject     Principal the record is keyed against.
     * @param   string  $operation   Method and path pair the key is scoped to.
     * @param   string  $key         The client's `Idempotency-Key`.
     * @param   string  $ownerToken  Token this request stored when it claimed the reservation.
     *
     * @return  bool  True when exactly this request's record was deleted.
     *
     * @since   2.0.0
     */
    public function release(string $subject, string $operation, string $key, string $ownerToken): bool
    {
        $affected = $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ? '
            . "AND state = 'in_progress' AND owner_token = ?",
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key, $ownerToken]);

        return $this->exactlyOne($affected);
    }

    /**
     * Re-arm a record as this request's reservation, but only while the caller's condition still holds.
     *
     * The single writer behind all three takeover paths. Its point is that the condition is carried into
     * the statement instead of being checked beforehand: the row is re-tested and claimed together, so
     * two claimants cannot both walk away believing they own the record. Everything the previous attempt
     * left is wiped — result columns cleared, creation, lease and expiry re-dated from now — so the
     * record is indistinguishable from a reservation taken by a first attempt.
     *
     * @param   string             $id                        Identifier of the record to claim.
     * @param   ?string            $requestDigest             New request digest, or null to leave the
     *          stored one untouched.
     * @param   string             $authorizationFingerprint  Credential and site fingerprint stored with
     *          the new reservation.
     * @param   string             $ownerToken                Token written to both the owner and lease
     *          columns to mark this request as holder.
     * @param   DateTimeImmutable  $now                       Instant the new lease and retention window
     *          are measured from.
     * @param   string             $condition                 SQL predicate ANDed with the identifier
     *          match, naming the state that makes this takeover legal.
     * @param   list<mixed>        $conditionValues           Values bound to the condition's placeholders,
     *          in the order they appear.
     * @param   list<string>       $conditionTypes            DBAL types for those values, positionally.
     *
     * @return  bool  True when exactly one row was claimed, which is the caller's proof of ownership.
     *
     * @since   2.0.0
     */
    private function reset(
        string $id,
        ?string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
        string $condition,
        array $conditionValues,
        array $conditionTypes,
    ): bool {
        $digestAssignment = $requestDigest === null ? '' : 'request_digest = ?, ';
        $values = $requestDigest === null ? [] : [$requestDigest];
        $types = $requestDigest === null ? [] : [Types::STRING];
        $values = array_merge($values, [
            $authorizationFingerprint,
            $ownerToken,
            $ownerToken,
            $now,
            $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
            $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
            $now->modify('+' . self::RETENTION_SECONDS . ' seconds'),
            $id,
        ], $conditionValues);
        $types = array_merge($types, [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
        ], $conditionTypes);
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET %sauthorization_fingerprint = ?, state = 'in_progress', owner_token = ?, "
            . 'lease_owner = ?, created_at = ?, locked_until = ?, lease_expires_at = ?, expires_at = ?, '
            . 'result_status = NULL, result_body = NULL, result_body_digest = NULL, '
            . 'result_headers = NULL, completed_at = NULL WHERE id = ? AND %s',
            $this->tables->quoted('idempotency'),
            $digestAssignment,
            $condition,
        ), $values, $types);

        return $this->exactlyOne($affected);
    }

    /**
     * Judge whether a conditional write landed on exactly one row, whatever spelling the driver used.
     *
     * Drivers disagree on whether an affected-row count comes back as an int or a decimal string, so the
     * comparison is made as text. Anything but one row means the caller's claim no longer held.
     *
     * @param   int|string  $affected  Rows the statement reported changing.
     *
     * @return  bool  True when the count spells exactly one.
     *
     * @since   2.0.0
     */
    private function exactlyOne(int|string $affected): bool
    {
        return (string) $affected === '1';
    }
}
