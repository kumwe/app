<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Idempotency;

/**
 * Contract for the ledger behind token mutations, whose stored replays must never carry the secret.
 *
 * This is the second flavour of the idempotency seam: `SecretOnceIdempotencyMiddleware` guards the two
 * routes whose success body carries a live credential, and its ledger differs from `IdempotencyLedger`
 * on purpose — a short lease because a token mutation is one quick write, a single combined takeover for
 * failed, expired and lapsed records, a locked ownership re-proof inside the caller's transaction, and a
 * rewrite hook that strips a legacy stored secret on its first replay. Application owns the contract;
 * `DoctrineSecretOnceIdempotencyLedger` adapts it, and no signature here names a driver type.
 *
 * @since  2.0.0
 */
interface SecretOnceIdempotencyLedger
{
    /**
     * Reserve a key for this request by inserting an in-progress record, losing cleanly on a collision.
     *
     * The implementation dates its short processing lease and the retention window from its own clock,
     * and must let the storage's uniqueness rule decide between simultaneous first attempts.
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
     * The projection is limited to what comparison and replay need; ownership is settled later, under a
     * lock, by `confirmLease()`.
     *
     * @param   string  $subject    Principal the record is keyed against.
     * @param   string  $operation  Method and path pair the key is scoped to.
     * @param   string  $key        The client's `Idempotency-Key`.
     *
     * @return  ?array<string, mixed>  The stored record keyed by column name — digests, state, result
     *          columns and expiry instants, each exactly as stored — or null when the record vanished
     *          between the collision and this read.
     *
     * @since   2.0.0
     */
    public function find(string $subject, string $operation, string $key): ?array;

    /**
     * Take over a record that failed, expired, or whose lease has lapsed, re-proving that in the write.
     *
     * Everything the previous attempt left is wiped and the record is re-dated as a fresh reservation,
     * so a winner is indistinguishable from a first attempt. A record still live and owned is left
     * alone, which is what the false return reports.
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
    ): bool;

    /**
     * Re-prove, under a storage-level lock, that this request still owns the reservation.
     *
     * Called inside the caller's transaction so the lock holds until the completion write commits. The
     * implementation must compare the stored fingerprint in constant time, so a lease claimed under one
     * set of credentials cannot be spent under another.
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
    ): bool;

    /**
     * Settle the record as completed and store the secret-free result future repeats are answered with.
     *
     * The write must re-state the whole claim — owner token, fingerprint, in-progress state, unlapsed
     * lease — so a lost race answers false rather than storing a replay over a record another request
     * owns. The implementation derives and stores the body's integrity digest beside it.
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
    ): bool;

    /**
     * Replace a stored result body whose legacy copy still carried the secret.
     *
     * This is the second line of defence behind completion-time stripping: a record stored before that
     * stripping applied is made safe on its first replay instead of handing the secret out again. The
     * implementation re-derives the integrity digest for the replacement body.
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
    public function rewriteStoredResult(string $subject, string $operation, string $key, string $body): void;

    /**
     * Give up a reservation this request still holds, after the operation failed.
     *
     * The record is deleted rather than marked failed, so the key is completely free for another
     * attempt. The owner token and the in-progress state both condition the delete, so a record another
     * request has since taken over, or one that already completed, is left untouched.
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
    public function release(string $subject, string $operation, string $key, string $ownerToken): void;
}
