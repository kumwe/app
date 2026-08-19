<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

/**
 * Direction of a stable report-output sort.
 *
 * @since  2.0.0
 */
enum ReportSortDirection: string
{
    /** Smallest values first. @since 2.0.0 */
    case Ascending = 'asc';
    /** Largest values first. @since 2.0.0 */
    case Descending = 'desc';
}
