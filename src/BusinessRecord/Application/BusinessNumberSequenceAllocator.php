<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;

/**
 * Reserves the next value of one business number counter, inside the caller's own transaction.
 *
 * This is the primitive behind `core.sequence`, and its whole reason to exist is that a document number
 * is not a deduplicated string: an invoice run has to be *contiguous*, which a unique index can only
 * ever refuse to break, never produce. An implementation therefore serializes concurrent allocators on
 * the counter row and advances it by exactly one, and it must do so in the transaction the record
 * command already opened — that is what makes a rolled-back create give its number back instead of
 * burning it.
 *
 * The guarantee an implementation owes, stated exactly:
 *
 * - Within one counter — site, definition, field handle, scope key and period key together — the values
 *   handed to *committed* records are contiguous from one, with no duplicates and no gaps.
 * - A command that rolls back for any reason, including a later failure in the same transaction,
 *   consumes nothing.
 * - A replayed idempotent command allocates nothing; it returns the number the original command stored.
 * - Numbers are *not* re-used, and a gap can still appear afterwards if a numbered row is hard-deleted
 *   or an operator edits the counter. Gaplessness is a property of allocation, not of the row surviving.
 * - Allocation holds an exclusive lock on the counter row until the enclosing transaction ends, so
 *   concurrent creates against one counter run one at a time. That is the price of contiguity, and it is
 *   the reason the scope and reset period on a `core.sequence` field are worth choosing deliberately.
 *
 * @since  2.0.0
 */
interface BusinessNumberSequenceAllocator
{
    /**
     * Reserve the next value of the counter these coordinates name.
     *
     * @param   string             $siteIdentifier  Site the numbered record belongs to.
     * @param   string             $definitionId    UUID of the definition declaring the sequence field.
     * @param   string             $fieldHandle     Handle of the `core.sequence` field being filled.
     * @param   string             $scopeKey        Tenancy key from `NumberSequenceFormat::counter()`.
     * @param   string             $periodKey       Period key from that same call; empty for a lifetime run.
     * @param   DateTimeImmutable  $now             Instant stamped on the counter row.
     *
     * @return  int  The reserved value, exactly one higher than the last committed allocation.
     *
     * @throws  BusinessRecordTemporarilyUnavailable  When another allocator was mid-flight on the same
     *          counter and this attempt must be replayed rather than guess at a value.
     *
     * @since   2.0.0
     */
    public function allocate(
        string $siteIdentifier,
        string $definitionId,
        string $fieldHandle,
        string $scopeKey,
        string $periodKey,
        DateTimeImmutable $now,
    ): int;
}
