<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * One entry in the idempotency ledger: the request that claimed a key, and the answer it may replay.
 *
 * This is where the replay rules live, so no caller has to re-derive them. The request itself is never
 * kept — only the SHA-256 of its canonical encoding — so a repeat can be proved identical without the
 * ledger retaining a payload, and a repeat that differs is rejected rather than quietly served the
 * first request's answer. Every rule that could make a stored answer wrong is enforced in one place:
 * the constructor refuses a record whose result and state disagree, and each transition returns a new
 * instance rather than mutating the one already handed out.
 *
 * The private constructor makes `begin()` the only way in and `complete()` or `fail()` the only ways
 * out of `IN_PROGRESS`, so the lifecycle cannot be entered halfway or run backwards. Subject and
 * operation travel with the key because a key means nothing on its own — a store scopes it to one
 * caller and one operation before looking it up.
 *
 * @since  2.0.0
 */
final readonly class IdempotencyRecord
{
    /**
     * Assemble a record, refusing any combination of fields the ledger's invariants forbid.
     *
     * @param   IdempotencyKey      $key            Caller-supplied token this entry is filed under.
     * @param   string              $subject        Principal the key belongs to; blank is refused.
     * @param   string              $operation      Operation the key was claimed for; blank is refused.
     * @param   string              $requestDigest  Canonical digest of the claiming request, as 64 lowercase
     *          hexadecimal characters.
     * @param   IdempotencyState    $state          Stage this entry has reached.
     * @param   ?IdempotencyResult  $result         Captured response; present exactly when the state is
     *          COMPLETED, and null in every other stage.
     * @param   DateTimeImmutable   $createdAt      Instant the key was claimed.
     * @param   DateTimeImmutable   $expiresAt      Instant from which the entry stops being replayable; must
     *          be strictly after the creation instant.
     *
     * @throws  InvalidArgumentException  When the subject or operation is empty once trimmed, the digest is
     *          not a lowercase SHA-256, the expiry is not after the creation instant, or a result is present
     *          without the COMPLETED state or missing with it.
     *
     * @since   2.0.0
     */
    private function __construct(
        private IdempotencyKey $key,
        private string $subject,
        private string $operation,
        private string $requestDigest,
        private IdempotencyState $state,
        private ?IdempotencyResult $result,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
    ) {
        if (trim($subject) === '' || trim($operation) === '') {
            throw new InvalidArgumentException('Idempotency subject and operation are required.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $requestDigest) !== 1) {
            throw new InvalidArgumentException('The idempotency request digest must be SHA-256.');
        }

        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('An idempotency record must expire after it is created.');
        }

        if (($state === IdempotencyState::COMPLETED) !== ($result !== null)) {
            throw new InvalidArgumentException('Only completed idempotency records may contain a result.');
        }
    }

    /**
     * Claim a key for a request that has not run yet.
     *
     * The request is fingerprinted here and then dropped, which is what lets a later replay be checked
     * against it without the ledger holding the payload for the length of the retention window.
     *
     * @param   IdempotencyKey     $key        Caller-supplied token to claim.
     * @param   string             $subject    Principal claiming the key.
     * @param   string             $operation  Operation the key is being claimed for.
     * @param   mixed              $request    Request payload to fingerprint; must be representable as
     *          canonical JSON, so null, scalars and arrays of those, and nothing else.
     * @param   DateTimeImmutable  $createdAt  Instant the claim is made.
     * @param   DateTimeImmutable  $expiresAt  Instant from which the claim stops being replayable.
     *
     * @return  self  A record in the IN_PROGRESS state, carrying no result yet.
     *
     * @throws  InvalidArgumentException  When the subject or operation is empty once trimmed, the expiry is
     *          not after the creation instant, or the request holds a value canonical JSON cannot represent.
     *
     * @since   2.0.0
     */
    public static function begin(
        IdempotencyKey $key,
        string $subject,
        string $operation,
        mixed $request,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            $key,
            $subject,
            $operation,
            CanonicalJson::digest($request),
            IdempotencyState::IN_PROGRESS,
            null,
            $createdAt,
            $expiresAt,
        );
    }

    /**
     * Return the token this entry is filed under.
     *
     * @return  IdempotencyKey  The already-validated key the claim was opened with.
     *
     * @since   2.0.0
     */
    public function key(): IdempotencyKey
    {
        return $this->key;
    }

    /**
     * Return the principal the claim belongs to.
     *
     * @return  string  Subject identifier; one half of the scope a key is only unique within.
     *
     * @since   2.0.0
     */
    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * Return the operation the key was claimed for.
     *
     * @return  string  Operation name; the other half of the scope a key is only unique within.
     *
     * @since   2.0.0
     */
    public function operation(): string
    {
        return $this->operation;
    }

    /**
     * Return the fingerprint of the request that opened the claim.
     *
     * This is all the record keeps of that request, and it is what a later replay is checked against.
     *
     * @return  string  Lowercase hexadecimal SHA-256, 64 characters wide.
     *
     * @since   2.0.0
     */
    public function requestDigest(): string
    {
        return $this->requestDigest;
    }

    /**
     * Report which stage of the lifecycle the entry has reached.
     *
     * @return  IdempotencyState  COMPLETED is the only stage from which a result can be replayed.
     *
     * @since   2.0.0
     */
    public function state(): IdempotencyState
    {
        return $this->state;
    }

    /**
     * Return the instant the entry stops being replayable.
     *
     * @return  DateTimeImmutable  Always strictly later than the instant the claim was opened.
     *
     * @since   2.0.0
     */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Decide whether the retention window has closed as of a given instant.
     *
     * The boundary is inclusive: the entry counts as expired at exactly its expiry instant, not only
     * after it, so a purge and a replay reading the same clock never disagree about a borderline entry.
     *
     * @param   DateTimeImmutable  $time  Instant to judge the entry against, normally the clock's reading.
     *
     * @return  bool  True when the entry can no longer be replayed.
     *
     * @since   2.0.0
     */
    public function isExpiredAt(DateTimeImmutable $time): bool
    {
        return $time >= $this->expiresAt;
    }

    /**
     * Confirm a request is the one this key was claimed for, and refuse it otherwise.
     *
     * Both sides are reduced to canonical digests first, so two payloads differing only in the order
     * their keys were built in still match. The comparison itself runs in constant time, so a caller
     * cannot narrow down the stored digest by timing how far a mismatch got.
     *
     * @param   mixed  $request  Request payload presented under the claimed key.
     *
     * @return  void
     *
     * @throws  DomainException  When the digests differ, meaning the key is being reused for a different
     *          request than the one it was claimed for.
     * @throws  InvalidArgumentException  When the request holds a value canonical JSON cannot represent.
     *
     * @since   2.0.0
     */
    public function assertRequestMatches(mixed $request): void
    {
        if (!hash_equals($this->requestDigest, CanonicalJson::digest($request))) {
            throw new DomainException('The idempotency key has already been used for a different request.');
        }
    }

    /**
     * Close the claim successfully, attaching the answer a later replay will hand back.
     *
     * @param   IdempotencyResult  $result  Status and body the operation produced.
     *
     * @return  self  A new COMPLETED record carrying the result; the receiver is left untouched.
     *
     * @throws  DomainException  When the claim is not open, because it has already completed or failed.
     *
     * @since   2.0.0
     */
    public function complete(IdempotencyResult $result): self
    {
        if ($this->state !== IdempotencyState::IN_PROGRESS) {
            throw new DomainException('Only an in-progress idempotency record can be completed.');
        }

        return new self(
            $this->key,
            $this->subject,
            $this->operation,
            $this->requestDigest,
            IdempotencyState::COMPLETED,
            $result,
            $this->createdAt,
            $this->expiresAt,
        );
    }

    /**
     * Close the claim without an answer, after the operation did not produce one.
     *
     * The entry is kept rather than discarded, so the key stays accounted for until it expires instead
     * of looking unclaimed to the next request that presents it.
     *
     * @return  self  A new FAILED record carrying no result; the receiver is left untouched.
     *
     * @throws  DomainException  When the claim is not open, because it has already completed or failed.
     *
     * @since   2.0.0
     */
    public function fail(): self
    {
        if ($this->state !== IdempotencyState::IN_PROGRESS) {
            throw new DomainException('Only an in-progress idempotency record can fail.');
        }

        return new self(
            $this->key,
            $this->subject,
            $this->operation,
            $this->requestDigest,
            IdempotencyState::FAILED,
            null,
            $this->createdAt,
            $this->expiresAt,
        );
    }

    /**
     * Answer a repeat of the claimed request from the stored result.
     *
     * Every condition that would make a replay wrong is checked before the result is released: the
     * request must fingerprint to the same value, the retention window must still be open, and the
     * operation must have completed. An open claim is refused rather than waited on, so a caller that
     * repeats a request while the first attempt is still running is told to come back instead of being
     * given a stale or absent answer.
     *
     * @param   mixed              $request  Request payload presented under the claimed key.
     * @param   DateTimeImmutable  $time     Instant to judge the retention window against.
     *
     * @return  IdempotencyResult  The result captured when the operation completed.
     *
     * @throws  DomainException  When the request differs from the one claimed, the entry has expired, the
     *          operation is still in progress, or it ended without a result.
     * @throws  InvalidArgumentException  When the request holds a value canonical JSON cannot represent.
     *
     * @since   2.0.0
     */
    public function replay(mixed $request, DateTimeImmutable $time): IdempotencyResult
    {
        $this->assertRequestMatches($request);

        if ($this->isExpiredAt($time)) {
            throw new DomainException('The idempotency record has expired.');
        }

        if ($this->state === IdempotencyState::IN_PROGRESS) {
            throw new DomainException('The idempotent operation is still in progress.');
        }

        if ($this->state === IdempotencyState::FAILED || $this->result === null) {
            throw new DomainException('The idempotent operation did not complete successfully.');
        }

        return $this->result;
    }
}
