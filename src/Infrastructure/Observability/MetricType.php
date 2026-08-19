<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

/**
 * The three metric shapes Kumwe exposes, named as the Prometheus exposition format names them.
 *
 * The set is deliberately short. Summaries are omitted because their quantiles cannot be aggregated
 * across replicas, which is exactly the operation an operator running several web containers needs;
 * histograms answer the same latency question and do aggregate.
 *
 * @since  2.0.0
 */
enum MetricType: string
{
    /**
     * A value that only ever increases, reset only when the process family loses its store.
     *
     * @since  2.0.0
     */
    case Counter = 'counter';

    /**
     * A value read fresh at scrape time that may move in either direction.
     *
     * @since  2.0.0
     */
    case Gauge = 'gauge';

    /**
     * Cumulative bucket counts plus a sum and a count, for a distribution such as request duration.
     *
     * @since  2.0.0
     */
    case Histogram = 'histogram';
}
