<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

use InvalidArgumentException;
use Kumwe\Conversion\Value\ConvertedMoneyValue;
use Kumwe\Conversion\Value\ConvertedQuantityValue;

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

    /** A lowercase handle or canonical record UUID. @since 2.0.0 */
    case Identifier = 'identifier';

    /** An ISO calendar date. @since 2.0.0 */
    case Date = 'date';

    /** An RFC 3339 instant or zoned date-time. @since 2.0.0 */
    case DateTime = 'date_time';

    /**
     * An amount presented in a currency it is not stored in, inseparable from its provenance.
     *
     * A report or export cell carries a scalar, so a converted amount travels as the self-describing
     * text `ConvertedMoneyValue::toPortableString()` writes. Declaring a column this type is what makes
     * the provenance mandatory rather than a rendering choice: a bare figure fails the column's own type
     * check and the report is refused, so a converted figure cannot reach an artifact stripped of the
     * rate, the as-at instant and the provider that produced it.
     *
     * @since  2.0.0
     */
    case ConvertedMoney = 'converted_money';

    /**
     * A quantity expressed in a unit it is not stored in, inseparable from its provenance.
     *
     * The same rule as `ConvertedMoney`, for the other denomination a business document carries. A
     * report or export cell carries a scalar, so a converted quantity travels as the self-describing
     * text `ConvertedQuantityValue::toPortableString()` writes, and a bare figure fails the column's own
     * type check rather than reaching an artifact stripped of the factor, the as-at instant and the
     * provider that produced it.
     *
     * @since  2.0.0
     */
    case ConvertedQuantity = 'converted_quantity';

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
            self::ConvertedMoney => self::acceptsConvertedMoney($value),
            self::ConvertedQuantity => self::acceptsConvertedQuantity($value),
            self::Identifier => is_string($value) && (
                preg_match('/^[a-z][a-z0-9_.-]{0,190}$/D', $value) === 1
                || preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                    $value,
                ) === 1
            ),
            self::Date => is_string($value)
                && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) === 1,
            self::DateTime => is_string($value)
                && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[^\x00-\x1f]{1,64}$/D', $value) === 1,
        };
    }

    /**
     * Validate a converted-money scalar by reconstructing the provenance it claims.
     *
     * Grammar alone cannot prove the converted amount follows from the source amount and rate. Parsing
     * invokes the value object's arithmetic, instant and rounding invariants before a report accepts it.
     *
     * @param   mixed  $value  Candidate report cell or parameter value.
     *
     * @return  bool  True only when the bounded text reconstructs as consistent converted money.
     *
     * @since   2.0.0
     */
    private static function acceptsConvertedMoney(mixed $value): bool
    {
        if (!is_string($value) || mb_strlen($value) > 512) {
            return false;
        }

        try {
            ConvertedMoneyValue::fromPortableString($value);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * Validate a converted-quantity scalar by reconstructing the provenance it claims.
     *
     * @param   mixed  $value  Candidate report cell or parameter value.
     *
     * @return  bool  True only when the bounded text reconstructs as a consistent converted quantity.
     *
     * @since   2.0.0
     */
    private static function acceptsConvertedQuantity(mixed $value): bool
    {
        if (!is_string($value) || mb_strlen($value) > 512) {
            return false;
        }

        try {
            ConvertedQuantityValue::fromPortableString($value);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
