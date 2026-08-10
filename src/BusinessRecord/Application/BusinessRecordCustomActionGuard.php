<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Application\Command\ExecuteRecordActionCommand;

/**
 * Canonical record-policy, concurrency, condition, capability, and approval guard for custom actions.
 *
 * The generated-business layer owns extension handler dispatch and its outer idempotency envelope, but
 * it must not recreate the record service's raw-record authorization rules. This narrow inward-facing
 * port lets that executor prove an action attempt under the mutation fence before extension code runs.
 *
 * @since  2.0.0
 */
interface BusinessRecordCustomActionGuard
{
    /**
     * Prove one custom action attempt against the current record and consume exact approval when required.
     *
     * The caller already owns the surrounding transaction, idempotency claim, and exclusive definition
     * fence. Any failure aborts that transaction, including approval consumption, before a handler can run.
     *
     * @param   ExecuteRecordActionCommand         $command     Validated custom action attempt.
     * @param   BusinessRecordMutationGeneration  $generation  Exclusive installed-definition generation.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordNotFound  When the target or
     *          action is absent, denied, or outside row policy.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordVersionConflict  On a stale
     *          expected version.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordActionRejected  When the action
     *          is not a custom declaration or its record condition fails.
     * @throws  \Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalDenied  When a required exact
     *          maker-checker approval is absent, stale, or already consumed.
     *
     * @since   2.0.0
     */
    public function guardCustomAction(
        ExecuteRecordActionCommand $command,
        BusinessRecordMutationGeneration $generation,
    ): void;
}
