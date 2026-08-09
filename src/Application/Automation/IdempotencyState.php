<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

/**
 * Stage one entry in the idempotency ledger has reached.
 *
 * An entry is opened `IN_PROGRESS` when a key is claimed and leaves that stage exactly once, for
 * `COMPLETED` if a result was captured or `FAILED` if none was. `IdempotencyRecord` hangs its
 * invariants on that split: a result may be carried only alongside `COMPLETED`, only an `IN_PROGRESS`
 * entry may still transition, and only a `COMPLETED` one can answer a replay. The backing strings are
 * the values the API idempotency table keeps in its `state` column, so the enum and a stored row spell
 * the same three stages.
 *
 * `BusinessRecord\Domain\BusinessRecordIdempotencyState` models the same three stages for the
 * business-record command ledger; the two are separate types because the ledgers are separate.
 *
 * @since  2.0.0
 */
enum IdempotencyState: string
{
    /**
     * The key is claimed and the operation behind it has not resolved yet.
     *
     * There is no result to hand back, so a repeat of the request is refused outright rather than made
     * to wait for the first attempt to finish.
     *
     * @since  2.0.0
     */
    case IN_PROGRESS = 'in_progress';
    /**
     * The operation finished and its response is stored, so a repeat is answered from the entry.
     *
     * @since  2.0.0
     */
    case COMPLETED = 'completed';
    /**
     * The operation ended without a usable response, leaving the entry with nothing to replay.
     *
     * @since  2.0.0
     */
    case FAILED = 'failed';
}
