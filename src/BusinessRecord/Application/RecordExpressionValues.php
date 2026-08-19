<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessRecord\Domain\ExactDecimal;
use Kumwe\App\BusinessRecord\Domain\MoneyValue;
use Kumwe\App\BusinessRecord\Domain\QuantityValue;
use Kumwe\App\BusinessRecord\Domain\ZonedDateTimeValue;

/**
 * Converts normalized record values into the scalar vocabulary definition expressions can evaluate.
 *
 * Conditions and formulas operate on the same canonical spellings at every application boundary. Exact
 * decimals stay strings, temporal values become stable ISO-8601 strings, and composite values without one
 * unambiguous scalar spelling become null. No PHP float is ever admitted to the result.
 *
 * @since  2.0.0
 */
final class RecordExpressionValues
{
    /**
     * Flatten one normalized record value set for expression evaluation.
     *
     * @param   array<string, mixed>  $values  Normalized record values keyed by field handle.
     *
     * @return  array<string, bool|int|string|null>  Scalar values under the same field handles.
     *
     * @since   2.0.0
     */
    public static function from(array $values): array
    {
        $result = [];
        foreach ($values as $handle => $value) {
            $result[$handle] = match (true) {
                $value instanceof ExactDecimal => $value->value(),
                $value instanceof DateTimeImmutable => $value->format('Y-m-d\TH:i:s.uP'),
                $value instanceof ZonedDateTimeValue => $value->instant->format('Y-m-d\TH:i:s.u\Z'),
                $value instanceof MoneyValue, $value instanceof QuantityValue => null,
                is_bool($value), is_int($value), is_string($value), $value === null => $value,
                default => null,
            };
        }

        return $result;
    }

    /**
     * Static utility; instances would carry no state.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
