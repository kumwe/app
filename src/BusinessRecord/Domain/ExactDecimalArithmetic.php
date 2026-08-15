<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;

/**
 * Exact base-10 multiplication and rounding for the money and quantity conversion contracts.
 *
 * `ExactDecimal` deliberately offers no arithmetic, because a stored value is never computed on. A
 * conversion does compute, and it must do so without a float ever appearing: the product of an amount
 * and a rate is built digit by digit here and stays a canonical literal from end to end. Rounding is a
 * separate, named step rather than a consequence of the multiplication, so the unrounded product
 * remains available to `ConvertedMoneyValue` and `ConvertedQuantityValue` and the relationship between
 * the two stays checkable.
 *
 * Nothing in this class touches storage. It exists so that conversion arithmetic has one implementation
 * instead of one per caller, and so that `ExactDecimal` itself is not widened to carry operators it has
 * no use for.
 *
 * @since  2.0.0
 */
final class ExactDecimalArithmetic
{
    /**
     * Widest digit budget a portable database column admits, and therefore the budget a product uses.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_PRECISION = 65;

    /**
     * Multiply two exact values into their full-width product, losing nothing.
     *
     * The product's scale is exactly the sum of the operands' scales, which is the only scale at which
     * the multiplication is lossless; narrowing it is `round()`'s job and the caller's declared choice.
     * The result is carried at the portable maximum precision because it belongs to no field — it is
     * the intermediate the conversion later rounds and publishes as evidence.
     *
     * @param   ExactDecimal  $left   Left operand, typically the amount being converted.
     * @param   ExactDecimal  $right  Right operand, typically the rate applied to it.
     *
     * @return  ExactDecimal  The exact product, carrying the summed scale of both operands.
     *
     * @throws  InvalidArgumentException  When the summed scale, or the product's digit count, is wider
     *          than a portable exact value can hold.
     *
     * @since   2.0.0
     */
    public static function multiply(ExactDecimal $left, ExactDecimal $right): ExactDecimal
    {
        $scale = $left->scale + $right->scale;
        if ($scale > self::MAXIMUM_PRECISION) {
            throw new InvalidArgumentException('An exact product is wider than the portable decimal range.');
        }
        $negative = str_starts_with($left->value(), '-') !== str_starts_with($right->value(), '-');
        $digits = self::multiplyDigits(self::digits($left), self::digits($right));

        return ExactDecimal::fromString(
            self::literal($digits, $scale, $negative),
            self::MAXIMUM_PRECISION,
            $scale,
        );
    }

    /**
     * Narrow an exact value to a declared scale under a declared rounding rule.
     *
     * Widening is allowed and is not rounding: a value already inside the requested scale is simply
     * re-stated at the target precision, so a caller never has to branch on whether rounding was
     * needed. Narrowing splits the digits, hands the four facts that decide the outcome to the declared
     * `ExactRoundingRule`, and increments the retained magnitude when the rule says so.
     *
     * @param   ExactDecimal       $value      Value to narrow, usually the unrounded conversion product.
     * @param   int                $precision  Total digit budget of the field the result belongs to.
     * @param   int                $scale      Fractional digits the result keeps.
     * @param   ExactRoundingRule  $mode       Declared rule applied to the discarded digits.
     *
     * @return  ExactDecimal  The value at $scale fractional digits under $mode.
     *
     * @throws  InvalidArgumentException  When precision or scale is outside the portable range, or the
     *          rounded result no longer fits the requested precision.
     *
     * @since   2.0.0
     */
    public static function round(
        ExactDecimal $value,
        int $precision,
        int $scale,
        ExactRoundingRule $mode,
    ): ExactDecimal {
        if ($scale >= $value->scale) {
            return ExactDecimal::fromString($value->value(), $precision, $scale);
        }
        $negative = str_starts_with($value->value(), '-');
        $digits = self::digits($value);
        $keptLength = strlen($digits) - ($value->scale - $scale);
        $kept = substr($digits, 0, $keptLength);
        $dropped = substr($digits, $keptLength);
        $remainder = ltrim(substr($dropped, 1), '0') !== '';
        $lastKept = $kept === '' ? '0' : $kept[strlen($kept) - 1];
        $firstDropped = (int) ($dropped === '' ? '0' : $dropped[0]);
        if ($mode->increments($firstDropped, $remainder, ((int) $lastKept) % 2 === 1, $negative)) {
            $kept = self::increment($kept);
        }

        return ExactDecimal::fromString(self::literal($kept, $scale, $negative), $precision, $scale);
    }

    /**
     * Reconstitute a canonical literal at the narrowest precision and scale that hold it exactly.
     *
     * Provenance travels as text — in an export cell, a report column, an API response — so a converted
     * amount has to be rebuildable from its literals alone. A canonical literal always carries exactly
     * its own scale in fractional digits and no insignificant leading zero, so both are recoverable and
     * the value comes back byte-identical without the export having to disclose a field's digit budget.
     *
     * @param   string  $value  Canonical base-10 literal as `ExactDecimal::value()` produced it.
     *
     * @return  ExactDecimal  The same value, at its own minimal precision and its own scale.
     *
     * @throws  InvalidArgumentException  When the literal is not canonical base-10, or needs more digits
     *          than a portable exact value allows.
     *
     * @since   2.0.0
     */
    public static function fromLiteral(string $value): ExactDecimal
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException('An exact decimal literal must be canonical base-10.');
        }
        $unsigned = ltrim($value, '-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $scale = strlen($fraction);
        $precision = max(1, strlen(ltrim($integer, '0')) + $scale);

        return ExactDecimal::fromString($value, $precision, $scale);
    }

    /**
     * Read one value as its unsigned digit string, with the decimal point removed.
     *
     * @param   ExactDecimal  $value  Value whose canonical literal is being decomposed.
     *
     * @return  string  Digits only, most significant first, at least one character long.
     *
     * @since   2.0.0
     */
    private static function digits(ExactDecimal $value): string
    {
        return str_replace('.', '', ltrim($value->value(), '-'));
    }

    /**
     * Multiply two unsigned digit strings with schoolbook long multiplication.
     *
     * Each partial product is accumulated into a fixed-width digit array rather than a PHP number, so
     * operands well past the integer range multiply exactly. The array is one digit wider than the sum
     * of the operand lengths can ever need, which is what removes the final carry as a special case.
     *
     * @param   string  $left   Unsigned digits of the left operand.
     * @param   string  $right  Unsigned digits of the right operand.
     *
     * @return  string  Unsigned digits of the product, with insignificant leading zeros removed.
     *
     * @since   2.0.0
     */
    private static function multiplyDigits(string $left, string $right): string
    {
        $digit = static fn (string $character): int => (int) $character;
        $leftDigits = array_map($digit, str_split($left));
        $rightDigits = array_map($digit, str_split($right));
        $product = array_fill(0, count($leftDigits) + count($rightDigits), 0);
        for ($i = count($leftDigits) - 1; $i >= 0; --$i) {
            $carry = 0;
            for ($j = count($rightDigits) - 1; $j >= 0; --$j) {
                $total = $product[$i + $j + 1] + ($leftDigits[$i] * $rightDigits[$j]) + $carry;
                $product[$i + $j + 1] = $total % 10;
                $carry = intdiv($total, 10);
            }
            $product[$i] += $carry;
        }
        $digits = ltrim(implode('', $product), '0');

        return $digits === '' ? '0' : $digits;
    }

    /**
     * Add one to an unsigned digit string, growing it by a digit when the carry runs off the top.
     *
     * @param   string  $digits  Unsigned digits to increment; an empty string counts as zero.
     *
     * @return  string  The incremented digits, most significant first.
     *
     * @since   2.0.0
     */
    private static function increment(string $digits): string
    {
        $result = str_split($digits === '' ? '0' : $digits);
        for ($index = count($result) - 1; $index >= 0; --$index) {
            $digit = (int) $result[$index] + 1;
            $result[$index] = (string) ($digit % 10);
            if ($digit < 10) {
                return implode('', $result);
            }
        }

        return '1' . implode('', $result);
    }

    /**
     * Spell an unsigned digit string as a canonical signed literal at one scale.
     *
     * @param   string  $digits    Unsigned digits representing the value multiplied by ten to the $scale.
     * @param   int     $scale     Fractional digits the literal carries.
     * @param   bool    $negative  Whether the value sits below zero.
     *
     * @return  string  Canonical base-10 literal `ExactDecimal::fromString()` accepts.
     *
     * @since   2.0.0
     */
    private static function literal(string $digits, int $scale, bool $negative): string
    {
        $digits = $digits === '' ? '0' : $digits;
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, strlen($digits) - $scale);
        $integer = ltrim($integer, '0');
        $literal = ($negative ? '-' : '') . ($integer === '' ? '0' : $integer);
        if ($scale > 0) {
            $literal .= '.' . substr($digits, strlen($digits) - $scale);
        }

        return $literal;
    }

    /**
     * Prevent instantiation; the arithmetic is a pure rule reached through its static methods alone.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
