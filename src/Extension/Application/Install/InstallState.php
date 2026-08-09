<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Install;

/**
 * Where one extension installation attempt stands in its lifecycle.
 *
 * `AtomicInstallPlan` holds exactly one of these and consults it before every transition, so this value
 * is what makes an out-of-turn step, a second start, or a commit with work outstanding refusable rather
 * than merely discouraged. Only `Executing` admits progress reports; `Committed` and `RolledBack` are
 * settled. The backing strings are stable machine identifiers and are also the words that appear in the
 * `InvalidInstallTransition` message naming the required and the actual state, so operators read them.
 *
 * @since  2.0.0
 */
enum InstallState: string
{
    /**
     * Described and validated, but nothing has been attempted yet.
     *
     * The state every plan is constructed in, and the only one `start()` accepts.
     *
     * @since  2.0.0
     */
    case Planned = 'planned';
    /**
     * Underway: actions may be reported complete, and the install may still commit, fail, or roll back.
     *
     * @since  2.0.0
     */
    case Executing = 'executing';
    /**
     * Stopped with a recorded failure code, with nothing yet undone.
     *
     * Recording the failure undoes nothing: the completed actions are left on the plan exactly as they
     * were, and remain the list a compensating rollback has to unwind.
     *
     * @since  2.0.0
     */
    case Failed = 'failed';
    /**
     * Compensation is under way; the actions already carried out are being undone.
     *
     * @since  2.0.0
     */
    case RollingBack = 'rolling_back';
    /**
     * Compensation finished, so the attempt left nothing behind. A settled state.
     *
     * Any failure code recorded before the rollback survives, so a plan here still reports why it was
     * abandoned.
     *
     * @since  2.0.0
     */
    case RolledBack = 'rolled_back';
    /**
     * Every declared action completed and the install stands. A settled state.
     *
     * @since  2.0.0
     */
    case Committed = 'committed';
}
