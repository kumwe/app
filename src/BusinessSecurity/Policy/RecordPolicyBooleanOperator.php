<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Policy;

/**
 * Boolean composition available to a record-policy expression.
 *
 * @since  2.0.0
 */
enum RecordPolicyBooleanOperator: string
{
    /** Every child predicate must hold. @since 2.0.0 */
    case All = 'all';

    /** At least one child predicate must hold. @since 2.0.0 */
    case Any = 'any';
}
