<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

/**
 * Port through which monitoring, readiness and diagnostics read a store's six retention metrics.
 *
 * Every implementation owes bounded cost: an observation must be a fixed number of indexed probes
 * that do not grow with table size, because it is read on every scrape and every readiness poll. An
 * exact count of a hot ledger is a diagnostic, not an observation, and belongs behind an explicit cost
 * class and timeout elsewhere.
 *
 * @since  2.0.0
 */
interface RetentionObserver
{
    /**
     * Observe one store.
     *
     * @param   RetentionStore  $store  Store to observe.
     *
     * @return  RetentionObservation  The six metrics with their qualifying flags.
     *
     * @since   2.0.0
     */
    public function observe(RetentionStore $store): RetentionObservation;

    /**
     * Observe every declared store.
     *
     * @return  list<RetentionObservation>  One observation per catalogue entry, in catalogue order.
     *
     * @since   2.0.0
     */
    public function observeAll(): array;
}
