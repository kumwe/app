<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

/**
 * Result of one atomic attempt to claim a preview transport sequence.
 *
 * @since  2.0.0
 */
enum StudioPreviewSequenceClaim
{
    /**
     * The candidate was the exact next sequence and is now owned by this request.
     *
     * @since  2.0.0
     */
    case Accepted;

    /**
     * Only the candidate's immediate predecessor remains unclaimed.
     *
     * @since  2.0.0
     */
    case PredecessorPending;

    /**
     * The candidate is replayed, duplicated, or separated from the ledger by a larger gap.
     *
     * @since  2.0.0
     */
    case Refused;
}
