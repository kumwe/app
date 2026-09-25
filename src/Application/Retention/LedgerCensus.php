<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

/**
 * Operator-only exact row counts of the hot stores, with an explicit cost class and a hard timeout.
 *
 * Monitoring and readiness never call this: they read bounded probes through `RetentionObserver`. An
 * exact count of a hot ledger is a table or index scan whose cost grows with the ledger, so it exists
 * only as a diagnostic an operator asks for on purpose — the recovery and diagnostics surface composes
 * it — and every call runs under a statement timeout that cancels the scan on the server rather than
 * leaving it to hold a snapshot open against live traffic.
 *
 * @since  2.0.0
 */
interface LedgerCensus
{
    /**
     * Cost class of every census statement.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string COST_CLASS = 'table_scan';

    /**
     * Longest timeout a caller may request.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_TIMEOUT_MILLISECONDS = 30_000;

    /**
     * Count one store exactly, cancelling the statement at the timeout.
     *
     * @param   RetentionStore  $store                Store to count.
     * @param   int             $timeoutMilliseconds  Statement timeout, 1 to `MAXIMUM_TIMEOUT_MILLISECONDS`.
     *
     * @return  LedgerCount  The exact count, or a timed-out outcome with a null count.
     *
     * @throws  \InvalidArgumentException  When the timeout is outside its range.
     *
     * @since   2.0.0
     */
    public function count(RetentionStore $store, int $timeoutMilliseconds = 5_000): LedgerCount;
}
