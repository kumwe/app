<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

/**
 * How a `BooleanFilter` combines the filters nested under it.
 *
 * Every composite predicate in a business-record query is such a group: one of these three operators
 * over between one and sixteen children. `Not` is the case that carries a constraint of its own — it
 * accepts exactly one child, which `BooleanFilter` enforces — and the compiler renders it so that a
 * child which evaluates to unknown counts as unmatched, keeping negation total over nullable columns
 * rather than silently dropping those rows.
 *
 * @since  2.0.0
 */
enum BooleanOperator: string
{
    /**
     * Conjunction: the record matches only when every child filter matches.
     *
     * @since  2.0.0
     */
    case All = 'all';

    /**
     * Disjunction: the record matches when at least one child filter matches.
     *
     * @since  2.0.0
     */
    case Any = 'any';

    /**
     * Negation of the single child: the record matches when the child does not match it.
     *
     * A record the child cannot decide, because the column it tests is null, is treated as not
     * matching the child and therefore matches the negation.
     *
     * @since  2.0.0
     */
    case Not = 'not';
}
