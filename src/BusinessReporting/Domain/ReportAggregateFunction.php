<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

/**
 * Exact aggregate functions available to grouped report output.
 *
 * @since  2.0.0
 */
enum ReportAggregateFunction: string
{
    /** Number of rows in a group. @since 2.0.0 */
    case Count = 'count';
    /** Exact sum of a numeric column. @since 2.0.0 */
    case Sum = 'sum';
    /** Smallest non-null column value. @since 2.0.0 */
    case Minimum = 'min';
    /** Largest non-null column value. @since 2.0.0 */
    case Maximum = 'max';
    /** Exact mean of a numeric column. @since 2.0.0 */
    case Average = 'avg';
}
