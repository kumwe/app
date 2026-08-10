<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Domain;

/**
 * Closed scalar vocabulary accepted by report parameters, columns and formulas.
 *
 * @since  2.0.0
 */
enum ReportValueType: string
{
    /** A true or false value. @since 2.0.0 */
    case Boolean = 'boolean';

    /** A signed whole number. @since 2.0.0 */
    case Integer = 'integer';

    /** A canonical base-ten number represented without a float. @since 2.0.0 */
    case Decimal = 'decimal';

    /** Bounded human-readable text. @since 2.0.0 */
    case String = 'string';

    /** A lowercase report or record identifier. @since 2.0.0 */
    case Identifier = 'identifier';

    /** An ISO calendar date. @since 2.0.0 */
    case Date = 'date';

    /** An RFC 3339 instant or zoned date-time. @since 2.0.0 */
    case DateTime = 'date_time';

    /**
     * Prove that an inbound parameter value has this exact type.
     *
     * @param   mixed  $value  Scalar value supplied by an authenticated report caller.
     *
     * @return  bool  True only when the value is bounded and canonical for this type.
     *
     * @since   2.0.0
     */
    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::Boolean => is_bool($value),
            self::Integer => is_int($value),
            self::Decimal => is_int($value)
                || (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1),
            self::String => is_string($value) && mb_strlen($value) <= 4096,
            self::Identifier => is_string($value)
                && preg_match('/^[a-z][a-z0-9_.-]{0,190}$/D', $value) === 1,
            self::Date => is_string($value)
                && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) === 1,
            self::DateTime => is_string($value)
                && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[^\x00-\x1f]{1,64}$/D', $value) === 1,
        };
    }
}
