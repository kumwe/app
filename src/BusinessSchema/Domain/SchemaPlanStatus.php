<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

/**
 * Position of a schema plan in the plan, approve, execute, recover lifecycle.
 *
 * The status is what separates the three independently authorized stages: a plan is compiled and stored
 * before anyone may approve it, approved against its exact checksum before anyone may execute it, and left
 * in an explicit interrupted state rather than a guessed one when a step cannot be reconciled. `SchemaPlan`
 * enforces which execution evidence each status may carry, so an impossible combination cannot be persisted.
 *
 * @since  2.0.0
 */
enum SchemaPlanStatus: string
{
    /**
     * Compiled and persisted for inspection, carrying no approval, fence, or outcome.
     *
     * @since  2.0.0
     */
    case PendingApproval = 'pending_approval';

    /**
     * Bound to approval evidence for this exact canonical plan and ready for an executor.
     *
     * @since  2.0.0
     */
    case Approved = 'approved';

    /**
     * Running under a monotonic execution fence, with its journal being applied step by step.
     *
     * @since  2.0.0
     */
    case Executing = 'executing';

    /**
     * Every operation applied and verified against live introspection.
     *
     * @since  2.0.0
     */
    case Completed = 'completed';

    /**
     * Stopped on a recorded error code with the physical state still understood from the journal.
     *
     * @since  2.0.0
     */
    case Failed = 'failed';

    /**
     * Stopped where the journal and live schema disagree, so an operator must inspect before resuming.
     *
     * @since  2.0.0
     */
    case RecoveryRequired = 'recovery_required';

    /**
     * Reconciled after an interruption, recording that the partial effects were resolved.
     *
     * @since  2.0.0
     */
    case Compensated = 'compensated';

    /**
     * Withdrawn without ever holding an execution fence; the plan's transitions never produce it.
     *
     * @since  2.0.0
     */
    case Cancelled = 'cancelled';

    /**
     * Report whether the plan has reached a state no further transition leaves.
     *
     * Callers such as the reactivation guard use this to distinguish an unfinished execution, which blocks
     * an installation from becoming usable again, from one that has been settled either way.
     *
     * @return  bool  True for completed, compensated, and cancelled plans.
     *
     * @since   2.0.0
     */
    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Compensated, self::Cancelled], true);
    }
}
