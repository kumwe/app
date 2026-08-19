<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

/**
 * How many of a record's related records must satisfy the filter nested inside a `RelationFilter`.
 *
 * These three cases are the whole vocabulary a relation hop offers, and each compiles to a different
 * shape around the same correlated `EXISTS`: an existence test, its negation, or the negation of a
 * search for a related record that fails the nested filter. That last form spells failure with a
 * `CASE` expression instead of SQL `NOT`, so a comparison that is unknown for a related record counts
 * against `All` rather than being quietly ignored.
 *
 * @since  2.0.0
 */
enum RelationQuantifier: string
{
    /**
     * Matches when at least one related record satisfies the nested filter.
     *
     * @since  2.0.0
     */
    case Any = 'any';

    /**
     * Matches when no related record satisfies the nested filter, a record with no relations included.
     *
     * @since  2.0.0
     */
    case None = 'none';

    /**
     * Matches when every related record satisfies the nested filter, vacuously so when there are none.
     *
     * @since  2.0.0
     */
    case All = 'all';
}
