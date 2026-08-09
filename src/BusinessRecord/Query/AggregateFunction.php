<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

/**
 * Summary a report query computes over the business records a filter selects.
 *
 * `RecordAggregate` pairs one of these with an output alias and, for every case but `Count`, a field
 * handle; the query compiler renders the case as the SQL function of the same name over that field's
 * single physical column. The set is closed on purpose, and each case is further restricted to columns
 * that answer it the same way everywhere: `Sum` and `Average` are refused over anything but an exact
 * numeric column, and `Minimum` and `Maximum` over a column the supported engines do not order
 * identically.
 *
 * @since  2.0.0
 */
enum AggregateFunction: string
{
    /**
     * How many records matched, counted over rows rather than over a nominated field.
     *
     * `RecordAggregate` ties the two together: this is the one case that must omit a field, and every
     * other case must name one.
     *
     * @since  2.0.0
     */
    case Count = 'count';

    /**
     * Total of an exact numeric field across the matching records.
     *
     * @since  2.0.0
     */
    case Sum = 'sum';

    /**
     * Lowest value the field takes across the matching records.
     *
     * @since  2.0.0
     */
    case Minimum = 'min';

    /**
     * Highest value the field takes across the matching records.
     *
     * @since  2.0.0
     */
    case Maximum = 'max';

    /**
     * Mean of an exact numeric field across the matching records, computed by the database.
     *
     * @since  2.0.0
     */
    case Average = 'avg';
}
