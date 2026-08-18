<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Idempotency;

/**
 * Contract for the durable ledger that lets a replay-protected mutation run at most once per key.
 *
 * Application owns this contract for the same reason it owns `TransactionManager`: which operations are
 * replay-protected, and what a reservation means, are use-case decisions, while the store that keeps
 * them is a driver detail. `PersistentIdempotencyMiddleware` walks this port through a whole reservation
 * lifecycle — reserve, read back after a collision, take a dead record over, complete, release — and
 * `DoctrineIdempotencyLedger` is the shipped adapter. Nothing here names a connection, a platform or a
 * query builder, so the delivery layer that consumes it never sees the database it writes.
 *
 * A record is keyed by subject, operation and idempotency key, and the implementation must arbitrate
 * concurrent first attempts on that identity itself — `reserve()` is expected to be decided by a unique
 * constraint rather than by a read that another request could interleave with. Every conditional write
 * answers with a truthful boolean: true only when this caller's claim landed on exactly one record.
 *
 * @since  2.0.0
 */
interface IdempotencyLedger
{
    /**
     * Reserve a key for this request by inserting an in-progress record, losing cleanly on a collision.
     *
     * The implementation dates the processing lease and the retention window from its own clock, and
     * must let the storage's uniqueness rule decide between simultaneous first attempts.
     *
     * @param   string  $subject                   Principal the record is keyed against.
     * @param   string  $operation                 Method and path pair the key is scoped to.
     * @param   string  $key                       The client's validated `Idempotency-Key`.
     * @param   string  $requestDigest             Digest of the request the key is being spent on.
     * @param   string  $authorizationFingerprint  Credential and site fingerprint stored with the claim.
     * @param   string  $ownerToken                Random token marking the reservation as this request's.
     *
     * @return  bool  True when the reservation is now this request's; false when a record for this
     *          subject, operation and key already existed.
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
    ): bool;

    /**
     * Read the stored record a failed reservation collided with.
     *
     * @param   string  $subject    Principal the record is keyed against.
     * @param   string  $operation  Method and path pair the key is scoped to.
     * @param   string  $key        The client's `Idempotency-Key`.
     *
     * @return  ?array<string, mixed>  The stored record keyed by column name — identity, digests, state,
     *          lock instant, result columns and expiry, each exactly as stored — or null when the record
     *          vanished between the collision and this read.
     *
     * @since   2.0.0
     */
    public function find(string $subject, string $operation, string $key): ?array;

    /**
     * Claim a record whose retention window has already closed, re-proving expiry inside the write.
     *
     * The stored digest is replaced, because an expired record no longer speaks for any particular
     * request: its key is free for whatever content the new claimant carries.
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
    ): bool;

    /**
     * Claim a record left behind by an attempt that ended in failure, re-proving that state in the write.
     *
     * @param   string  $id                        Identifier of the record being retried.
     * @param   string  $requestDigest             Digest of this request, equal to the stored one.
     * @param   string  $authorizationFingerprint  Fingerprint stored with the new reservation.
     * @param   string  $ownerToken                Token proving the new reservation is this request's.
     *
     * @return  bool  True when this request now owns the record; false when another retry claimed it first.
     *
     * @since   2.0.0
     */
    public function takeOverFailed(
        string $id,
        string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
    ): bool;

    /**
     * Claim an in-progress record whose processing lease has run out, re-proving the lapse in the write.
     *
     * No digest is passed, because the caller has already proved its digest equals the stored one, so
     * there is nothing to rewrite.
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
    public function takeOverStale(string $id, string $authorizationFingerprint, string $ownerToken): bool;

    /**
     * Settle the record as completed and store the result future repeats are answered with.
     *
     * The write must re-state the whole claim — same owner token, still in progress, lease not yet
     * lapsed — so a lost race answers false rather than overwriting a record another request now owns.
     * The implementation derives and stores the body's integrity digest beside it.
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
    ): bool;

    /**
     * Give the key back after an attempt that did not settle, deleting only this request's reservation.
     *
     * The owner token and the in-progress state both condition the delete, so a record another attempt
     * has since taken over, or one that already completed, is untouched.
     *
     * @param   string  $subject     Principal the record is keyed against.
     * @param   string  $operation   Method and path pair the key is scoped to.
     * @param   string  $key         The client's `Idempotency-Key`.
     * @param   string  $ownerToken  Token this request stored when it claimed the reservation.
     *
     * @return  bool  True when exactly this request's record was deleted; false when it was no longer
     *          this request's to clear.
     *
     * @since   2.0.0
     */
    public function release(string $subject, string $operation, string $key, string $ownerToken): bool;
}
