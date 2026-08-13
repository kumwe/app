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
 * It also carries the observed append-only enforcement state, because "the trail verifies" answers a
 * narrower question than operators tend to hear in it. An intact chain on a server whose triggers were
 * never accepted is genuinely intact — nothing has been tampered with that the evidence can see — but
 * it is only *detecting* tampering, where a guarded server also *prevents* it. Reporting the two
 * together is what stops the weaker posture from reading as the stronger one.
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
     * @param  AuditEnforcementState      $enforcement      Append-only enforcement observed on this server.
     * @param  ?AuditVerificationFinding  $firstDivergence  First divergence found, or null for an intact trail.
     *
     * @since  2.0.0
     */
    public function __construct(
        public int $eventsVerified,
        public int $anchorsVerified,
        public int $headPosition,
        public AuditEnforcementState $enforcement,
        public ?AuditVerificationFinding $firstDivergence = null,
    ) {
    }

    /**
     * Report whether the walk completed without finding a divergence.
     *
     * This is a statement about the evidence only. A caller deciding whether the installation is in the
     * posture it is supposed to be in wants `guarded()`, which additionally requires that the database
     * is refusing rewrites rather than merely recording them.
     *
     * @return  bool  True when every checked event and anchor agreed with its recomputation.
     *
     * @since   2.0.0
     */
    public function intact(): bool
    {
        return $this->firstDivergence === null;
    }

    /**
     * Report whether the trail both verifies and is being prevented from changing.
     *
     * @return  bool  True only when the chain is intact and the append-only guards are installed.
     *
     * @since   2.0.0
     */
    public function guarded(): bool
    {
        return $this->intact() && $this->enforcement->installed();
    }
}
