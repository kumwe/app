<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * One at-most-once claim over a business-record command, carrying the result a retry replays.
 *
 * `BusinessRecordService` stores an in-progress claim under a scope digest before it applies a
 * mutation and completes it with the result in the same transaction, so a command repeated with the
 * same idempotency key finds this entry and replays its stored result instead of mutating twice. The
 * constructor is where a row read back from the ledger is proved trustworthy before any of it is
 * believed: identifiers, fingerprints and checksums must be well formed, the entry must expire after
 * it was created, and `Completed` must coincide exactly with a stored result, its checksum and a
 * completion instant — a half-written claim can therefore never become an object that replays.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordIdempotency
{
    /**
     * Result to hand back when the command is repeated, or null while the claim is unfinished.
     *
     * @var    array<string, mixed>|null
     * @since  2.0.0
     */
    private ?array $result;

    /**
     * Assemble a ledger claim and prove it is internally consistent.
     *
     * @param   string                          $id                        UUID of this ledger entry.
     * @param   string                          $scopeDigest               Digest over site,
     *          organization, actor, operation and key that names one logical command; the ledger holds
     *          at most one entry per value.
     * @param   string                          $siteIdentifier            Site the command ran against.
     * @param   string|null                     $organizationIdentifier    Organization branch within
     *          that site, or null when the command is site-wide.
     * @param   string                          $actorId                   Identity that issued the command.
     * @param   string                          $operation                 Lowercase name of the guarded
     *          command, such as `business.record.update`.
     * @param   string                          $operationId               Idempotency key the caller
     *          supplied, 8 to 128 characters.
     * @param   string                          $requestFingerprint        Digest of the request payload,
     *          re-checked on replay so one key cannot carry two different requests.
     * @param   string                          $authorizationFingerprint  Digest of the caller's
     *          authorization context, re-checked on replay so a key cannot cross a privilege boundary.
     * @param   BusinessRecordIdempotencyState  $state                     Whether the claim is still open
     *          or holds a replayable result.
     * @param   array<string, mixed>|null       $result                    Stored mutation result; null
     *          exactly while the claim is in progress.
     * @param   string|null                     $resultChecksum            Digest of $result, re-proved
     *          before a replay is trusted; null exactly while the claim is in progress.
     * @param   DateTimeImmutable               $createdAt                 Instant the claim was taken.
     * @param   DateTimeImmutable|null          $completedAt               Instant the mutation finished;
     *          null exactly while the claim is in progress.
     * @param   DateTimeImmutable               $expiresAt                 Instant after which the entry
     *          stops replaying and becomes collectable; must be later than $createdAt.
     *
     * @throws  InvalidArgumentException  When the ID is not a canonical UUID, a fingerprint or result
     *          checksum is not a 64-character hex digest, the operation identity is malformed, the
     *          entry does not expire after creation, or the state disagrees with the stored result.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $scopeDigest,
        public string $siteIdentifier,
        public ?string $organizationIdentifier,
        public string $actorId,
        public string $operation,
        public string $operationId,
        public string $requestFingerprint,
        public string $authorizationFingerprint,
        public BusinessRecordIdempotencyState $state,
        ?array $result,
        public ?string $resultChecksum,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $completedAt,
        public DateTimeImmutable $expiresAt,
    ) {
        if (!Uuid::isValid($id)) {
            throw new InvalidArgumentException('A business-record idempotency ID must be a canonical UUID.');
        }
        foreach ([$scopeDigest, $requestFingerprint, $authorizationFingerprint] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('A business-record idempotency fingerprint is invalid.');
            }
        }
        if ($resultChecksum !== null && preg_match('/^[a-f0-9]{64}$/D', $resultChecksum) !== 1) {
            throw new InvalidArgumentException('A business-record idempotency result checksum is invalid.');
        }
        if (
            preg_match('/^[a-z][a-z0-9._:-]{0,95}$/D', $operation) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $operationId) !== 1
        ) {
            throw new InvalidArgumentException('A business-record idempotency operation identity is invalid.');
        }
        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('A business-record idempotency entry must expire after creation.');
        }
        if (
            ($state === BusinessRecordIdempotencyState::Completed)
            !== ($result !== null && $resultChecksum !== null && $completedAt !== null)
        ) {
            throw new InvalidArgumentException('A business-record idempotency result state is inconsistent.');
        }
        $this->result = $result;
    }

    /**
     * Return the recorded outcome that a repeat of this command replays.
     *
     * @return  array<string, mixed>|null  The stored result, or null while the claim is still in progress.
     *
     * @since   2.0.0
     */
    public function result(): ?array
    {
        return $this->result;
    }

    /**
     * Decide whether a repeated command was issued with the same request and authorization.
     *
     * A mismatch means the key has been reused for different work, which the caller must reject
     * rather than replay. Both digests are compared with `hash_equals()`, so the check does not leak
     * how much of a guessed fingerprint was right.
     *
     * @param   string  $requestFingerprint        Digest of the repeated command's request payload.
     * @param   string  $authorizationFingerprint  Digest of the repeating caller's authorization context.
     *
     * @return  bool  True only when both digests equal the ones the claim was taken under.
     *
     * @since   2.0.0
     */
    public function matches(string $requestFingerprint, string $authorizationFingerprint): bool
    {
        return hash_equals($this->requestFingerprint, $requestFingerprint)
            && hash_equals($this->authorizationFingerprint, $authorizationFingerprint);
    }

    /**
     * Report whether this claim holds an outcome that can be handed back instead of re-running.
     *
     * @return  bool  True when the claim finished and its result is present.
     *
     * @since   2.0.0
     */
    public function isCompleted(): bool
    {
        return $this->state === BusinessRecordIdempotencyState::Completed && $this->result !== null;
    }
}
