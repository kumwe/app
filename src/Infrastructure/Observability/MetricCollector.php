<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

/**
 * The read side of metrics: values recomputed at scrape time rather than accumulated as they happen.
 *
 * Counters and gauges have genuinely different sources in this application. A counter is written by the
 * code path it observes and has to be stored somewhere that outlives the request; a gauge is already in
 * the database and only needs reading. Keeping the two behind separate contracts is what lets the
 * exposition endpoint combine them without either one growing a background process, and lets a test
 * exercise the endpoint without a database.
 *
 * @since  2.0.0
 */
interface MetricCollector
{
    /**
     * Recompute every gauge this collector publishes.
     *
     * Implementations must not raise: the endpoint is most needed exactly when the stores behind it are
     * misbehaving, so a failed collection is reported as a sample rather than as a status code.
     *
     * @return  list<MetricSample>  The gauge samples for this scrape.
     *
     * @since   2.0.0
     */
    public function collect(): array;
}
