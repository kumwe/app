<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use RuntimeException;

/**
 * Verifies the audit trail against checkpoints retained outside the database it lives in.
 *
 * @since  2.0.0
 */
interface AuditContinuityVerifier
{
    /**
     * Verify the trail and ledger, and prove they still reach every retained or supplied checkpoint.
     *
     * @param   ExecutionContext  $context    Actor the verification is authorized under.
     * @param   int               $batchSize  Rows fetched per batch during the walk, from 1 to 10000.
     * @param   ?AuditCheckpoint  $external   Operator-retained checkpoint the trail must still reach.
     *
     * @return  AuditContinuityReport  The verdict, the retention authority posture and the reached mark.
     *
     * @throws  InvalidArgumentException  When the batch size is outside its bounds.
     * @throws  RuntimeException  When a reached checkpoint cannot be retained privately.
     * @throws  \Kumwe\Access\AuthorizationDenied  When the actor may not verify the audit trail.
     *
     * @since   2.0.0
     */
    public function verifyContinuity(
        ExecutionContext $context,
        int $batchSize = 1000,
        ?AuditCheckpoint $external = null,
    ): AuditContinuityReport;
}
