<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Idempotency\SecretOnceIdempotencyLedger;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Doctrine implementation of `SecretOnceIdempotencyLedger` over the shared `idempotency` table.
 *
 * The same table as `DoctrineIdempotencyLedger`, written under the token routes' stricter rules: a
 * two-minute lease, because a token mutation is a single quick write and a long lease would strand the
 * key after a crash; an attempt counter that survives takeovers; and a locked `SELECT … FOR UPDATE`
 * ownership re-proof that holds the row for the rest of the caller's transaction, so the ownership just
 * verified stays true until the completion write commits. Every conditional write re-states its claim
 * inside the statement, and the fingerprint comparison behind `confirmLease()` runs in constant time.
 *
 * @since  2.0.0
 */
final readonly class DoctrineSecretOnceIdempotencyLedger implements SecretOnceIdempotencyLedger
{
    /**
     * How long a reservation stays owned by the request that took it, as a `DateTimeImmutable` modifier.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LEASE = '+2 minutes';

    /**
     * Wire the ledger to the connection, the table map and the clock its leases are dated from.
     *
     * @param  Connection      $database  Connection the idempotency table is read and written on.
     * @param  TableNames      $tables    Resolves the physical `idempotency` table name.
     * @param  ClockInterface  $clock     Supplies the instants leases and expiry use.
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
                'lease_owner' => $ownerToken,
                'lease_expires_at' => $now->modify(self::LEASE),
                'attempt' => 1,
                'created_at' => $now,
                'expires_at' => $now->modify('+24 hours'),
            ], [
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
     * Read the record a losing insert collided with.
     *
     * The projection is limited to the columns the comparison and the replay need, so the owner token is
     * not read here — ownership is settled under a lock in `confirmLease()`.
     *
     * @param   string  $subject    Principal the record is keyed against.
     * @param   string  $operation  Method and path pair the key is scoped to.
     * @param   string  $key        The client's `Idempotency-Key`.
     *
     * @return  ?array<string, mixed>  The stored record keyed by column name, exactly as the driver
     *          typed it, or null when a purge or a competing delete landed in between.
     *
     * @since   2.0.0
     */
    public function find(string $subject, string $operation, string $key): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT request_digest, authorization_fingerprint, state, result_status, result_body, '
            . 'result_body_digest, result_headers, '
            . 'expires_at, lease_expires_at '
            . 'FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key]);

        return $row === false ? null : $row;
    }

    /**
     * Take over a record that failed, expired, or whose lease has lapsed, re-proving that in the write.
     *
     * The conditional update either succeeds outright or leaves the caller knowing another attempt is
     * still running; the attempt counter is carried forward so retries stay countable.
     *
     * @param   string  $subject                   Principal the record is keyed against.
     * @param   string  $operation                 Method and path pair the key is scoped to.
     * @param   string  $key                       The client's `Idempotency-Key`.
     * @param   string  $requestDigest             Digest of this request, replacing the stored one.
     * @param   string  $authorizationFingerprint  Fingerprint stored with the new reservation.
     * @param   string  $ownerToken                Token proving the new reservation is this request's.
     *
     * @return  bool  True when this request now owns the record; false when it is still someone else's.
     *
     * @since   2.0.0
     */
    public function takeOver(
        string $subject,
        string $operation,
        string $key,
        string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
    ): bool {
        $now = $this->clock->now();
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET request_digest = ?, authorization_fingerprint = ?, state = 'in_progress', "
            . 'owner_token = ?, lease_owner = ?, '
            . 'lease_expires_at = ?, attempt = attempt + 1, result_status = NULL, result_body = NULL, '
            . 'result_body_digest = NULL, result_headers = NULL, completed_at = NULL, created_at = ?, '
            . 'expires_at = ? WHERE subject = ? AND operation = ? AND idempotency_key = ? '
            . "AND (state = 'failed' OR expires_at <= ? "
            . "OR (state = 'in_progress' AND lease_expires_at <= ?))",
            $this->tables->quoted('idempotency'),
        ), [
            $requestDigest,
            $authorizationFingerprint,
            $ownerToken,
            $ownerToken,
            $now->modify(self::LEASE),
            $now,
            $now->modify('+24 hours'),
            $subject,
            $operation,
            $key,
            $now,
            $now,
        ], [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
        ]);

        return $affected === 1;
    }

    /**
     * Re-prove, under a row lock, that this request still owns the reservation.
     *
     * `SELECT … FOR UPDATE` holds the row for the rest of the caller's transaction, so the ownership
     * verified here stays true until the completion write commits. The authorization fingerprint is
     * compared in constant time, so a lease claimed under one set of credentials cannot be spent under
     * another.
     *
     * @param   string  $subject                   Principal the record is keyed against.
     * @param   string  $operation                 Method and path pair the key is scoped to.
     * @param   string  $key                       The client's `Idempotency-Key`.
     * @param   string  $ownerToken                Token this request stored when it claimed the lease.
     * @param   string  $authorizationFingerprint  Fingerprint that must still match the stored one.
     *
     * @return  bool  True when the record is present, in progress, this request's, fingerprint-matched
     *          and unlapsed; false on any other state.
     *
     * @since   2.0.0
     */
    public function confirmLease(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        string $authorizationFingerprint,
    ): bool {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT owner_token, authorization_fingerprint, state, lease_expires_at FROM %s '
            . 'WHERE subject = ? AND operation = ? '
            . 'AND idempotency_key = ? FOR UPDATE',
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key]);

        return $row !== false
            && ($row['owner_token'] ?? null) === $ownerToken
            && ($row['state'] ?? null) === 'in_progress'
            && is_string($row['authorization_fingerprint'] ?? null)
            && hash_equals($row['authorization_fingerprint'], $authorizationFingerprint)
            && is_string($row['lease_expires_at'] ?? null)
            && new DateTimeImmutable($row['lease_expires_at']) > $this->clock->now();
    }

    /**
     * Settle the record as completed and store the secret-free result future repeats are answered with.
     *
     * @param   string                 $subject                   Principal the record is keyed against.
     * @param   string                 $operation                 Method and path pair the key is scoped to.
     * @param   string                 $key                       The client's `Idempotency-Key`.
     * @param   string                 $ownerToken                Token this request claimed the lease with.
     * @param   string                 $authorizationFingerprint  Fingerprint that must still match the row.
     * @param   int                    $status                    HTTP status a replay must reproduce.
     * @param   string                 $body                      Secret-free body to store and replay.
     * @param   array<string, string>  $headers                   Header lines to replay, keyed by name.
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
        string $authorizationFingerprint,
        int $status,
        string $body,
        array $headers,
    ): bool {
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET state = 'completed', owner_token = NULL, lease_owner = NULL, "
            . 'result_status = ?, result_body = ?, '
            . 'result_body_digest = ?, result_headers = ?, completed_at = ?, lease_expires_at = NULL '
            . "WHERE subject = ? AND operation = ? AND idempotency_key = ? AND owner_token = ? "
            . "AND authorization_fingerprint = ? AND state = 'in_progress' AND lease_expires_at > ?",
            $this->tables->quoted('idempotency'),
        ), [
            $status,
            $body,
            hash('sha256', $body),
            $headers,
            $this->clock->now(),
            $subject,
            $operation,
            $key,
            $ownerToken,
            $authorizationFingerprint,
            $this->clock->now(),
        ], [
            Types::INTEGER,
            Types::TEXT,
            Types::STRING,
            Types::JSON,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
        ]);

        return $affected === 1;
    }

    /**
     * Replace a stored result body whose legacy copy still carried the secret.
     *
     * @param   string  $subject    Principal the record is keyed against.
     * @param   string  $operation  Method and path pair the key is scoped to.
     * @param   string  $key        The client's `Idempotency-Key`.
     * @param   string  $body       Secret-free replacement body to store.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function rewriteStoredResult(string $subject, string $operation, string $key, string $body): void
    {
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET result_body = ?, result_body_digest = ? '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $this->tables->quoted('idempotency'),
        ), [$body, hash('sha256', $body), $subject, $operation, $key]);
    }

    /**
     * Give up a reservation this request still holds, after the operation failed.
     *
     * @param   string  $subject     Principal the record is keyed against.
     * @param   string  $operation   Method and path pair the key is scoped to.
     * @param   string  $key         The client's `Idempotency-Key`.
     * @param   string  $ownerToken  Token this request stored when it claimed the lease.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function release(string $subject, string $operation, string $key, string $ownerToken): void
    {
        $this->database->executeStatement(sprintf(
            "DELETE FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ? "
            . "AND owner_token = ? AND state = 'in_progress'",
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key, $ownerToken]);
    }
}
