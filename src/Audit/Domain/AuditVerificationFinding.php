<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Domain;

/**
 * The first divergence a verification pass found in the audit trail or its anchor ledger.
 *
 * A finding names the machine-readable divergence class, the position it anchors to and an operator
 * message. Verification stops at the first divergence because everything after a broken link or a
 * mismatched digest is unreliable evidence: the point of the report is where trust ends, not an
 * inventory of every consequence downstream of it.
 *
 * @since  2.0.0
 */
final readonly class AuditVerificationFinding
{
    /**
     * Capture one divergence.
     *
     * @param  string   $code      Machine token classifying the divergence, such as `event.digest.mismatch`.
     * @param  int      $position  Audit or anchor position the divergence anchors to; zero when none applies.
     * @param  string   $detail    Operator-facing explanation of what disagreed.
     * @param  ?string  $eventId   UUID of the divergent audit row when the divergence names one.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $code,
        public int $position,
        public string $detail,
        public ?string $eventId = null,
    ) {
    }
}
