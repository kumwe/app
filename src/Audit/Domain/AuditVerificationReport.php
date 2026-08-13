<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Domain;

/**
 * Outcome of one full verification walk over the audit trail and its anchor ledger.
 *
 * The report either carries no finding — every event digest recomputed, every witness link resolved,
 * every anchor re-derived — or carries exactly the first divergence together with how far verification
 * got before finding it. The counts exist for evidence: a qualification run cites how many events and
 * anchors were actually re-checked, not merely that a command exited zero.
 *
 * @since  2.0.0
 */
final readonly class AuditVerificationReport
{
    /**
     * Capture the outcome of one verification pass.
     *
     * @param  int                        $eventsVerified   Audit rows whose digests and links were re-checked.
     * @param  int                        $anchorsVerified  Anchor rows whose chain and ranges were re-derived.
     * @param  int                        $headPosition     Highest audit position that existed during the walk.
     * @param  ?AuditVerificationFinding  $firstDivergence  First divergence found, or null for an intact trail.
     *
     * @since  2.0.0
     */
    public function __construct(
        public int $eventsVerified,
        public int $anchorsVerified,
        public int $headPosition,
        public ?AuditVerificationFinding $firstDivergence = null,
    ) {
    }

    /**
     * Report whether the walk completed without finding a divergence.
     *
     * @return  bool  True when every checked event and anchor agreed with its recomputation.
     *
     * @since   2.0.0
     */
    public function intact(): bool
    {
        return $this->firstDivergence === null;
    }
}
