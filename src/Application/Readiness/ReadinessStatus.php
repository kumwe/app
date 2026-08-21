<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Readiness;

/**
 * Contract for the single yes-or-no verdict on whether this process is fit to serve requests.
 *
 * The verdict is polled continuously and never cached — `ReadinessHandler` asks for it on every
 * `/health/ready` request — so each implementation decides for itself how much evidence one answer is
 * worth: `LocalRuntimeReadinessProbe` reads a replica-local marker and stays cheap, while
 * `ReadinessProbe` re-queries the database and the migration ledger on the spot. What every
 * implementation owes is the meaning of `false`: a dependency that is missing, unreachable, or out of
 * date is reported as not-ready rather than raised, because the caller has only a status code to give
 * back and a worker answering `false` is simply drained until the condition clears.
 *
 * @since  2.0.0
 */
interface ReadinessStatus
{
    /**
     * Report whether this process may take traffic right now.
     *
     * @return  bool  False while any dependency this implementation checks is missing, unreachable, or
     *          out of date, which keeps the worker drained until the condition clears.
     *
     * @since   2.0.0
     */
    public function ready(): bool;
}
