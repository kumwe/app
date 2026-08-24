<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

/**
 * Atomic monotonic replay ledger for each preview channel direction.
 *
 * @since  2.0.0
 */
interface StudioPreviewSequenceRepository
{
    /**
     * Attempt to advance exactly from the expected next sequence.
     *
     * @param   string  $resourceContextKey  Opaque trusted host context.
     * @param   string  $lane                Closed delivery direction, `port` or `document`.
     * @param   int     $sequence            Candidate zero-based sequence.
     *
     * @return  StudioPreviewSequenceClaim  Accepted, immediate-predecessor pending, or refused.
     *
     * @since   2.0.0
     */
    public function advance(
        string $resourceContextKey,
        string $lane,
        int $sequence,
    ): StudioPreviewSequenceClaim;
}
