<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

/**
 * Stage of a business-record command's idempotency claim as recorded in the command ledger.
 *
 * A claim is inserted `InProgress` inside the same transaction as the mutation it guards and moves to
 * `Completed` only once the result and its checksum are stored, so the two cases also say whether a
 * repeat of the command can be replayed. There is deliberately no failed case: a command that aborts
 * rolls its claim back with the work it guarded, and a claim left behind by a process that died is
 * collected once it expires.
 *
 * @since  2.0.0
 */
enum BusinessRecordIdempotencyState: string
{
    /**
     * Claimed but unfinished, so a repeat of the command is rejected rather than replayed.
     *
     * @since  2.0.0
     */
    case InProgress = 'in_progress';

    /**
     * Finished with its result and checksum stored, so a repeat of the command replays that result.
     *
     * @since  2.0.0
     */
    case Completed = 'completed';
}
