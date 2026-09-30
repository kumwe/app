<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

use Kumwe\Audit\Domain\AuditVerificationReport;

/**
 * A verification verdict together with the continuity and retention authority evidence it relied on.
 *
 * @since  2.0.0
 */
final readonly class AuditContinuityReport
{
    /**
     * Capture the verdict and the evidence around it.
     *
     * @param  AuditVerificationReport       $report              Chain, ledger and checkpoint verdict.
     * @param  AuditRetentionAuthorityState  $retentionAuthority  Who the database lets remove evidence.
     * @param  ?AuditCheckpoint              $checkpoint          Mark reached by a guarded trail, to retain
     *         off-host; null whenever the verdict is not both intact and guarded.
     * @param  ?AuditCheckpoint              $retained            Privately retained mark checked against, if any.
     *
     * @since  2.0.0
     */
    public function __construct(
        public AuditVerificationReport $report,
        public AuditRetentionAuthorityState $retentionAuthority,
        public ?AuditCheckpoint $checkpoint = null,
        public ?AuditCheckpoint $retained = null,
    ) {
    }
}
