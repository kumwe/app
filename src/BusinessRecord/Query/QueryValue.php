<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Value\MoneyValue;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\App\BusinessRecord\Domain\ZonedDateTimeValue;

/**
 * The closed set of literal types a business-record query is allowed to bind.
 *
 * Comparison values, set members and cursor sort values are all checked here as their node is built, so
 * the restriction holds across a whole query tree instead of being rediscovered by the compiler.
 * Floats are refused so a stored exact value is never matched against an approximation, strings are
 * capped so a filter cannot push an unbounded payload into a prepared statement, and arrays are refused
 * because a collection is expressed as a set filter whose members are each checked individually. Null
 * is accepted here, since a cursor sort value can legitimately read null; `ComparisonFilter` rejects it
 * separately, because asking whether a field equals null is `NullFilter`'s job.
 *
 * @since  2.0.0
 */
final class QueryValue
{
    /**
     * Require a literal to be one of the bounded types a query may bind.
     *
     * @param   mixed  $value  Candidate literal from a comparison, a set member, or a cursor sort value.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is a string longer than 4096 bytes, or is a
     *          float, an array, or any other runtime type outside null, bool, int, string and the date,
     *          decimal, money, quantity and zoned date-time value objects.
     *
     * @since   2.0.0
     */
    public static function assert(mixed $value): void
    {
        if (
            $value === null || is_bool($value) || is_int($value) || is_string($value)
            || $value instanceof DateTimeImmutable || $value instanceof ExactDecimal
            || $value instanceof MoneyValue || $value instanceof QuantityValue
            || $value instanceof ZonedDateTimeValue
        ) {
            if (is_string($value) && strlen($value) > 4096) {
                throw new InvalidArgumentException('A query value exceeds 4096 bytes.');
            }
            return;
        }

        throw new InvalidArgumentException('A query value must be a bounded typed scalar and cannot be a float.');
    }

    /**
     * Prevent instantiation; the accepted set is a static rule with no state.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
