<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordIdempotency;

/**
 * Port for the ledger that makes a typed record command run at most once per idempotency key.
 *
 * `BusinessRecordService` claims an entry before it applies a mutation and completes it with the
 * result in the same transaction, so a retry of the same key finds a finished entry and replays its
 * stored result instead of mutating twice. The store therefore has to be the arbiter of the race
 * rather than a cache of it: `begin()` is expected to be backed by a unique constraint on the scope
 * digest, and every write is expected to run inside the caller's transaction so that an abandoned
 * command rolls the claim back with the work it guarded. Entries carry an expiry, and
 * `BusinessRecordIdempotencyPurger` is what calls `purgeExpired()` to collect them.
 *
 * @since  2.0.0
 */
interface BusinessRecordIdempotencyRepository
{
    /**
     * Look up the entry a repeated command would replay.
     *
     * @param   string  $scopeDigest  Digest over site, organization, actor, operation, and key that
     *          identifies one logical command.
     *
     * @return  BusinessRecordIdempotency|null  The stored entry, or null when this key has never been
     *          claimed in this scope.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict  When
     *          the stored row cannot be reconstituted or its result no longer matches its checksum.
     *
     * @since   2.0.0
     */
    public function find(string $scopeDigest): ?BusinessRecordIdempotency;

    /**
     * Claim the key by storing an in-progress entry before the mutation is applied.
     *
     * This insert is where concurrent commands on the same key are decided, so the loser is expected
     * to be rejected by the store rather than by an earlier read.
     *
     * @param   BusinessRecordIdempotency  $entry  In-progress claim carrying the command's scope,
     *          fingerprints, and expiry.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordIdempotencyRace  When a
     *          concurrent command already claimed the same scope digest.
     *
     * @since   2.0.0
     */
    public function begin(BusinessRecordIdempotency $entry): void;

    /**
     * Finish a claimed entry by recording the result a later retry replays.
     *
     * The transition is expected to be conditional on the entry still being in progress, so a second
     * completion of the same claim fails rather than overwriting a stored result.
     *
     * @param   string                $id              UUID of the entry claimed by `begin()`.
     * @param   array<string, mixed>  $result          Outcome to hand back on a later replay.
     * @param   string                $resultChecksum  Digest of that result, re-proved before storing.
     * @param   DateTimeImmutable     $completedAt     Instant the mutation finished.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict  When
     *          the checksum does not describe the result, or the entry is no longer in progress.
     *
     * @since   2.0.0
     */
    public function complete(
        string $id,
        array $result,
        string $resultChecksum,
        DateTimeImmutable $completedAt,
    ): void;

    /**
     * Delete at most $limit completed-expired or abandoned in-progress entries.
     *
     * An in-progress entry counts as abandoned once it has expired and holds no live lease, which is
     * what stops a command that died mid-transaction from blocking its key forever. The bound is part
     * of the contract: retention runs against a live table and must not delete unboundedly.
     *
     * @param   DateTimeImmutable  $now    Instant expiry is measured against.
     * @param   int                $limit  Most entries to delete in this call.
     *
     * @return  int  Number of entries deleted; below $limit when nothing more had expired.
     *
     * @since   2.0.0
     */
    public function purgeExpired(DateTimeImmutable $now, int $limit): int;
}
