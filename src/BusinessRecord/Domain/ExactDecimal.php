<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * A fixed-scale base-10 value. PHP floats are deliberately outside this contract.
 *
 * Decimal, money and quantity fields exist as this string-backed type from the moment
 * `RecordValueCodec` accepts them until they reach their database column, because a float would lose
 * digits the field promised to keep — `RecordValueGuard` rejects floats in record values outright.
 * Construction goes through the factories only, so every instance is already canonical: padded to the
 * field scale, free of insignificant leading zeros, and with negative zero spelled as zero. That is
 * what lets `value()` be stored, checksummed and compared as a plain string.
 *
 * @since  2.0.0
 */
final readonly class ExactDecimal implements Stringable
{
    /**
     * Wrap a value that the factories have already canonicalised and proved against a field.
     *
     * @param  string  $value      Canonical literal as produced by `fromString()`.
     * @param  int     $precision  Total digit budget of the field the value belongs to.
     * @param  int     $scale      Fractional digit count the value is padded to.
     *
     * @since  2.0.0
     */
    private function __construct(
        private string $value,
        public int $precision,
        public int $scale,
    ) {
    }

    /**
     * Canonicalise a base-10 literal against the precision and scale of the field storing it.
     *
     * Nothing is repaired silently: exponent notation, a leading `+`, insignificant leading zeros and
     * a fraction wider than the field scale are all rejected rather than rounded or trimmed, so a
     * caller never gets back a number it did not supply. A shorter fraction is padded, since the
     * canonical form always carries exactly $scale fractional digits.
     *
     * @param   string  $value      Signed base-10 literal such as `-12.50`, without exponent or separators.
     * @param   int     $precision  Total digits the field allows, 1 to 65.
     * @param   int     $scale      Fractional digits the field stores, 0 to $precision.
     *
     * @return  self  The canonical value, padded to $scale fractional digits.
     *
     * @throws  InvalidArgumentException  When precision or scale falls outside the portable database
     *          range, the literal is not canonical base-10, its fraction is wider than the scale, or
     *          its integer digits exceed the precision left over after the scale.
     *
     * @since   2.0.0
     */
    public static function fromString(string $value, int $precision, int $scale): self
    {
        if ($precision < 1 || $precision > 65 || $scale < 0 || $scale > $precision) {
            throw new InvalidArgumentException('Decimal precision or scale is outside the portable database range.');
        }
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException('An exact decimal must be a canonical base-10 string.');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException('An exact decimal has more fractional digits than the field scale.');
        }

        $significantInteger = ltrim($integer, '0');
        $integerDigits = strlen($significantInteger);
        if ($integerDigits > $precision - $scale) {
            throw new InvalidArgumentException('An exact decimal exceeds the field precision.');
        }

        $fraction = str_pad($fraction, $scale, '0');
        $integer = $significantInteger === '' ? '0' : $significantInteger;
        $zero = $integer === '0' && ($fraction === '' || trim($fraction, '0') === '');
        $canonical = ($negative && !$zero ? '-' : '') . $integer;
        if ($scale > 0) {
            $canonical .= '.' . $fraction;
        }

        return new self($canonical, $precision, $scale);
    }

    /**
     * Lift a whole number into a field's precision and scale.
     *
     * @param   int  $value      Whole number to store; its fraction is filled with zeros.
     * @param   int  $precision  Total digits the field allows, 1 to 65.
     * @param   int  $scale      Fractional digits the field stores, 0 to $precision.
     *
     * @return  self  The canonical value, padded to $scale fractional digits.
     *
     * @throws  InvalidArgumentException  When precision or scale falls outside the portable database
     *          range, or the number needs more integer digits than the field leaves for them.
     *
     * @since   2.0.0
     */
    public static function fromInt(int $value, int $precision, int $scale): self
    {
        return self::fromString((string) $value, $precision, $scale);
    }

    /**
     * Return the canonical literal that is written to storage and folded into revision checksums.
     *
     * @return  string  Plain base-10 form carrying exactly the field's scale in fractional digits.
     *
     * @since   2.0.0
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Order this value against another taken from the same field.
     *
     * Comparison walks digits rather than converting to a number, so it stays exact well past the
     * range a PHP integer or float could hold. Equal scale is required rather than coerced: two
     * decimals of different scale come from different fields, and quietly aligning them would hide
     * that mismatch instead of surfacing it.
     *
     * @param   self  $other  Value from the same field, and therefore of the same scale.
     *
     * @return  int  Negative, zero or positive as this value sorts before, with, or after $other.
     *
     * @throws  InvalidArgumentException  When $other carries a different scale.
     *
     * @since   2.0.0
     */
    public function compare(self $other): int
    {
        if ($this->scale !== $other->scale) {
            throw new InvalidArgumentException('Exact decimals with different scales cannot be compared directly.');
        }

        return self::compareCanonical($this->value, $other->value);
    }

    /**
     * Render the canonical literal wherever a string is expected, such as query binding or logging.
     *
     * @return  string  The same text `value()` returns.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Order two canonical literals by sign, then digit count, then digits.
     *
     * The decimal point is dropped before comparing, so the digit strings only line up when both
     * sides carry the same scale; `compare()` proves that first.
     *
     * @param   string  $left   Canonical literal as produced by `fromString()`.
     * @param   string  $right  Canonical literal of the same scale to measure against.
     *
     * @return  int  Negative, zero or positive as $left sorts before, with, or after $right.
     *
     * @since   2.0.0
     */
    private static function compareCanonical(string $left, string $right): int
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $leftDigits = str_replace('.', '', ltrim($left, '-'));
        $rightDigits = str_replace('.', '', ltrim($right, '-'));
        $leftDigits = ltrim($leftDigits, '0');
        $rightDigits = ltrim($rightDigits, '0');
        $leftDigits = $leftDigits === '' ? '0' : $leftDigits;
        $rightDigits = $rightDigits === '' ? '0' : $rightDigits;
        $comparison = strlen($leftDigits) <=> strlen($rightDigits);
        if ($comparison === 0) {
            $comparison = $leftDigits <=> $rightDigits;
        }

        return $leftNegative ? -$comparison : $comparison;
    }
}
