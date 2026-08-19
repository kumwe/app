<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application;

/**
 * Handle on the exclusive claim one extension lifecycle operation holds over the registry.
 *
 * Only one install, activation, disable or uninstall may be in flight at a time, and the mutual
 * exclusion alone is not enough: a holder that stalls long enough for its claim to expire must not be
 * able to commit behind the operation that replaced it. The lease answers both halves. `renew()` keeps
 * the claim alive while work is still happening, and `fence()` reports the monotonic number the
 * registry compares every write against, so a superseded holder is rejected instead of overwriting a
 * newer result. Lifecycle events publish the same number as `registry_fence`, which lets durable
 * listeners discard side effects arriving from an expired holder.
 *
 * @since  2.0.0
 */
interface ExtensionRegistryLease
{
    /**
     * Report the fence number allocated to this lease.
     *
     * @return  int  Monotonically increasing token, higher than every fence issued before it; a registry
     *          write whose stored fence has moved past this value is refused.
     *
     * @since   2.0.0
     */
    public function fence(): int;

    /**
     * Extend the claim so a long-running operation keeps its exclusivity.
     *
     * Called repeatedly across the phases of one operation rather than once at the start, so that an
     * implementation can fail the operation the moment the claim has already been lost.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function renew(): void;
}
