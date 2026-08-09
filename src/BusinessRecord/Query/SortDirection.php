<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

/**
 * Which way one `RecordSort` key orders the rows of a business-record browse.
 *
 * Direction is not only presentation here: browse pages by keyset, so the same value that is uppercased
 * into the `ORDER BY` clause also decides which way the cursor predicate seeks — an ascending key looks
 * for rows above the last one on the previous page, a descending key for rows below it. A cursor is
 * therefore only meaningful under the directions it was issued with, which is part of what the query
 * digest stamped on it pins down.
 *
 * @since  2.0.0
 */
enum SortDirection: string
{
    /**
     * Smallest value first, and the direction a sort takes when the caller states none.
     *
     * @since  2.0.0
     */
    case Ascending = 'asc';

    /**
     * Largest value first; this is also the direction of the newest-updated-first ordering a browse falls
     * back on when it is given no sort keys at all.
     *
     * @since  2.0.0
     */
    case Descending = 'desc';
}
