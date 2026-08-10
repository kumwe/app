<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Domain;

/**
 * Bounded filter vocabulary translated into the business-record query AST.
 *
 * @since  2.0.0
 */
enum ReportFilterOperator: string
{
    /** Equality. @since 2.0.0 */
    case Equal = 'eq';
    /** Inequality. @since 2.0.0 */
    case NotEqual = 'ne';
    /** Strictly less than. @since 2.0.0 */
    case LessThan = 'lt';
    /** Less than or equal. @since 2.0.0 */
    case LessThanOrEqual = 'lte';
    /** Strictly greater than. @since 2.0.0 */
    case GreaterThan = 'gt';
    /** Greater than or equal. @since 2.0.0 */
    case GreaterThanOrEqual = 'gte';
    /** Case-insensitive substring match. @since 2.0.0 */
    case Contains = 'contains';
    /** Case-insensitive prefix match. @since 2.0.0 */
    case StartsWith = 'starts_with';
    /** Case-insensitive suffix match. @since 2.0.0 */
    case EndsWith = 'ends_with';
    /** Membership of a bounded set. @since 2.0.0 */
    case In = 'in';
    /** Exclusion from a bounded set. @since 2.0.0 */
    case NotIn = 'not_in';
    /** Absence of a value. @since 2.0.0 */
    case IsNull = 'is_null';
    /** Presence of a value. @since 2.0.0 */
    case IsNotNull = 'is_not_null';

    /**
     * Report whether this operator binds no parameter.
     *
     * @return  bool  True for the two explicit null tests.
     *
     * @since   2.0.0
     */
    public function isNullTest(): bool
    {
        return $this === self::IsNull || $this === self::IsNotNull;
    }
}
