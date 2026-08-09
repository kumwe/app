<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

/**
 * Test a `ComparisonFilter` applies between one field and one literal value.
 *
 * The set stops at the six tests the query compiler can render with the same meaning on every
 * supported engine; anything richer is expressed with the dedicated text, set, null or relation
 * filters instead. `Equal` and `NotEqual` are the only cases accepted against a composite field —
 * one the definition spreads over several physical columns — because ordering such a field has no
 * single meaning; the other four also insist on a column the engines order identically, which rules
 * out boolean and identifier columns that only compare for equality.
 *
 * @since  2.0.0
 */
enum ComparisonOperator: string
{
    /**
     * The field holds exactly this value; a composite field matches only when every column does.
     *
     * @since  2.0.0
     */
    case Equal = 'eq';

    /**
     * The field holds anything but this value, a null column included.
     *
     * A composite field differs as soon as one of its columns differs.
     *
     * @since  2.0.0
     */
    case NotEqual = 'ne';

    /**
     * The field orders strictly before this value.
     *
     * @since  2.0.0
     */
    case LessThan = 'lt';

    /**
     * The field orders before this value or equals it.
     *
     * @since  2.0.0
     */
    case LessThanOrEqual = 'lte';

    /**
     * The field orders strictly after this value.
     *
     * @since  2.0.0
     */
    case GreaterThan = 'gt';

    /**
     * The field orders after this value or equals it.
     *
     * @since  2.0.0
     */
    case GreaterThanOrEqual = 'gte';
}
