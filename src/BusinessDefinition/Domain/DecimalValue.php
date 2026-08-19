<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * Exact base-10 arithmetic on canonical decimal strings, used by definition formulas without PHP floats.
 *
 * A `decimal` formula has to produce the same digits in every process and on every engine, so
 * `ExpressionEvaluator` never lets a decimal operand reach PHP arithmetic: it parses each operand here,
 * works over the digit strings longhand, and renders the result back to a string. Sign, unsigned digits,
 * and scale are held apart, which is what keeps `'0.1' + '0.2'` exact and makes rounding something that
 * happens only where `divide()` was explicitly asked for a scale. `fromString()` is the only way in, and
 * it doubles as the gate that refuses anything but a canonical literal inside the digit budget.
 *
 * @since  2.0.0
 */
final readonly class DecimalValue
{
    /**
     * Most digits a parsed literal or a computed result may carry.
     *
     * The bound is what stops a formula from growing an operand without limit through repeated
     * multiplication, since each product is as long as its two operands together.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_DIGITS = 4096;

    /**
     * Assemble a value from its three parts, checking none of them.
     *
     * Being private is what makes that safe: only `fromString()`, `fromParts()`, and the negation inside
     * `subtract()` reach it, and each has settled the parts before it does.
     *
     * @param  bool    $negative  Whether the value is below zero; false for zero itself.
     * @param  string  $digits    Unsigned digit string with no decimal point, `'0'` for zero.
     * @param  int     $scale     How many of those digits sit after the decimal point.
     *
     * @since  2.0.0
     */
    private function __construct(private bool $negative, private string $digits, private int $scale)
    {
    }

    /**
     * Parse a canonical base-10 literal into a value the arithmetic methods accept.
     *
     * The written scale is kept as written, so `'2.00'` stays a two-place value and renders that way
     * again; only results computed from it are normalized. This is the sole entry point into the type,
     * so every instance in circulation has passed this check.
     *
     * @param   string  $value  An optional `-`, an integer part carrying no leading zeros, and an optional
     *          fraction — and nothing else once surrounding whitespace has been trimmed.
     *
     * @return  self  The parsed value, with leading zeros dropped and a zero result left unsigned.
     *
     * @throws  InvalidBusinessDefinition  When the string is not a canonical base-10 literal, or its
     *          integer and fraction digits together exceed 4096.
     *
     * @since   2.0.0
     */
    public static function fromString(string $value): self
    {
        $value = trim($value);
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidBusinessDefinition('A decimal value must be a canonical base-10 string.');
        }
        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        if (strlen($integer) + strlen($fraction) > self::MAX_DIGITS) {
            throw new InvalidBusinessDefinition('A decimal value exceeds 4096 digits.');
        }
        $digits = ltrim($integer . $fraction, '0');

        return new self($digits !== '' && $negative, $digits === '' ? '0' : $digits, strlen($fraction));
    }

    /**
     * Add another value, after padding both operands out to the larger of the two scales.
     *
     * @param   self  $other  Value to add to this one.
     *
     * @return  self  The sum, normalized so that trailing fraction zeros are gone and zero is unsigned.
     *
     * @throws  InvalidBusinessDefinition  When the sum needs more than 4096 digits.
     *
     * @since   2.0.0
     */
    public function add(self $other): self
    {
        [$left, $right, $scale] = $this->aligned($other);
        if ($this->negative === $other->negative) {
            return self::fromParts($this->negative, self::addAbs($left, $right), $scale);
        }
        $comparison = self::compareAbs($left, $right);
        if ($comparison === 0) {
            return self::fromParts(false, '0', $scale);
        }

        return $comparison > 0
            ? self::fromParts($this->negative, self::subtractAbs($left, $right), $scale)
            : self::fromParts($other->negative, self::subtractAbs($right, $left), $scale);
    }

    /**
     * Subtract another value by adding its negation.
     *
     * @param   self  $other  Value to subtract from this one.
     *
     * @return  self  The difference, normalized the same way a sum is.
     *
     * @throws  InvalidBusinessDefinition  When the difference needs more than 4096 digits.
     *
     * @since   2.0.0
     */
    public function subtract(self $other): self
    {
        return $this->add(new self(!$other->negative && $other->digits !== '0', $other->digits, $other->scale));
    }

    /**
     * Multiply by another value, using long multiplication over the two digit strings.
     *
     * The product is exact: its scale is the sum of the operand scales, and no rounding takes place.
     *
     * @param   self  $other  Value to multiply this one by.
     *
     * @return  self  The product, negative only when exactly one operand was.
     *
     * @throws  InvalidBusinessDefinition  When the product needs more than 4096 digits.
     *
     * @since   2.0.0
     */
    public function multiply(self $other): self
    {
        $left = strrev($this->digits);
        $right = strrev($other->digits);
        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = 0; $i < strlen($left); ++$i) {
            for ($j = 0; $j < strlen($right); ++$j) {
                $result[$i + $j] += ((int) $left[$i]) * ((int) $right[$j]);
            }
        }
        for ($index = 0; $index < count($result) - 1; ++$index) {
            $result[$index + 1] += intdiv($result[$index], 10);
            $result[$index] %= 10;
        }
        $digits = ltrim(implode('', array_reverse($result)), '0');

        return self::fromParts(
            $this->negative !== $other->negative,
            $digits === '' ? '0' : $digits,
            $this->scale + $other->scale,
        );
    }

    /**
     * Divide by another value and round the quotient to a requested number of decimal places.
     *
     * The scale is an argument rather than a derived property because an exact quotient often does not
     * exist. Rounding is half away from zero: a remainder of at least half the divisor lifts the last
     * kept digit, so `'1' / '3'` at scale two is `'0.33'` and `'2' / '3'` is `'0.67'`.
     *
     * @param   self  $other  Divisor.
     * @param   int   $scale  Decimal places the quotient is carried out to, from 0 to 30 inclusive.
     *
     * @return  self  The rounded quotient, negative only when exactly one operand was; its own scale may
     *          come out lower than the one asked for, because trailing fraction zeros are trimmed.
     *
     * @throws  InvalidBusinessDefinition  When the scale falls outside 0 to 30, the divisor is zero, or
     *          the quotient needs more than 4096 digits.
     *
     * @since   2.0.0
     */
    public function divide(self $other, int $scale): self
    {
        if ($scale < 0 || $scale > 30) {
            throw new InvalidBusinessDefinition('Decimal division scale must be between 0 and 30.');
        }
        if ($other->digits === '0') {
            throw new InvalidBusinessDefinition('A definition formula attempted division by zero.');
        }
        $power = $scale + $other->scale - $this->scale;
        $numerator = $this->digits . str_repeat('0', max(0, $power));
        $denominator = $other->digits . str_repeat('0', max(0, -$power));
        [$quotient, $remainder] = self::divideAbs($numerator, $denominator);
        $doubledRemainder = self::addAbs($remainder, $remainder);
        if (self::compareAbs($doubledRemainder, $denominator) >= 0) {
            $quotient = self::addAbs($quotient, '1');
        }

        return self::fromParts($this->negative !== $other->negative, $quotient, $scale);
    }

    /**
     * Order this value against another, on sign first and then on aligned magnitude.
     *
     * @param   self  $other  Value to compare against.
     *
     * @return  int  Negative, zero, or positive as this value sorts before, with, or after `$other`;
     *          equality is numeric, so a difference in written scale alone still compares equal.
     *
     * @since   2.0.0
     */
    public function compare(self $other): int
    {
        if ($this->negative !== $other->negative) {
            return $this->negative ? -1 : 1;
        }
        [$left, $right] = $this->aligned($other);
        $comparison = self::compareAbs($left, $right);

        return $this->negative ? -$comparison : $comparison;
    }

    /**
     * Render the value back to the canonical string form formulas and storage exchange.
     *
     * @return  string  A leading `-` only when the value is negative, then the integer part, then a point
     *          and exactly `scale` fraction digits — the point being omitted entirely at scale zero.
     *
     * @since   2.0.0
     */
    public function value(): string
    {
        $digits = $this->digits;
        if ($this->scale > 0) {
            $digits = str_pad($digits, $this->scale + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$this->scale) . '.' . substr($digits, -$this->scale);
        }

        return ($this->negative ? '-' : '') . $digits;
    }

    /**
     * Pad both operands to a common scale so their digits line up positionally.
     *
     * @param   self  $other  Value this one is about to be combined with or compared against.
     *
     * @return  array{0: string, 1: string, 2: int}  This value's padded digits, the other's, and the
     *          shared scale the two are now expressed at.
     *
     * @since   2.0.0
     */
    private function aligned(self $other): array
    {
        $scale = max($this->scale, $other->scale);

        return [
            $this->digits . str_repeat('0', $scale - $this->scale),
            $other->digits . str_repeat('0', $scale - $other->scale),
            $scale,
        ];
    }

    /**
     * Build a normalized value from the sign, digits, and scale an operation computed.
     *
     * Normalization is what makes equal results identical: leading zeros go, trailing fraction zeros are
     * dropped along with the scale they occupied, and zero is never carried as negative. Every arithmetic
     * result is built here, so no computed value renders as `'-0'` or `'1.500'` — only a literal keeps
     * the scale it was written with, because `fromString()` bypasses this.
     *
     * @param   bool    $negative  Sign the operation computed, ignored when the digits come to zero.
     * @param   string  $digits    Unsigned result digits, which may still carry leading or trailing zeros.
     * @param   int     $scale     Decimal places the digit string was computed at.
     *
     * @return  self  The normalized value.
     *
     * @throws  InvalidBusinessDefinition  When more than 4096 digits remain once leading zeros are gone.
     *
     * @since   2.0.0
     */
    private static function fromParts(bool $negative, string $digits, int $scale): self
    {
        $digits = ltrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;
        if (strlen($digits) > self::MAX_DIGITS) {
            throw new InvalidBusinessDefinition('A decimal result exceeds 4096 digits.');
        }
        while ($scale > 0 && str_ends_with($digits, '0')) {
            $digits = substr($digits, 0, -1);
            --$scale;
        }

        return new self($negative && $digits !== '0', $digits, $scale);
    }

    /**
     * Compare two unsigned digit strings by magnitude, disregarding leading zeros.
     *
     * The strings are read as plain integers, so callers align the two scales before calling this.
     *
     * @param   string  $left   Left digit string.
     * @param   string  $right  Right digit string.
     *
     * @return  int  Negative, zero, or positive as the left magnitude is the smaller, equal, or larger.
     *
     * @since   2.0.0
     */
    private static function compareAbs(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $left = $left === '' ? '0' : $left;
        $right = ltrim($right, '0');
        $right = $right === '' ? '0' : $right;
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return $left <=> $right;
    }

    /**
     * Add two unsigned digit strings, carrying from the least significant end.
     *
     * @param   string  $left   Left digit string.
     * @param   string  $right  Right digit string.
     *
     * @return  string  The sum, one digit longer than the longer operand when the final carry survives.
     *
     * @since   2.0.0
     */
    private static function addAbs(string $left, string $right): string
    {
        $left = strrev($left);
        $right = strrev($right);
        $carry = 0;
        $result = '';
        for ($index = 0, $length = max(strlen($left), strlen($right)); $index < $length; ++$index) {
            $sum = (int) ($left[$index] ?? '0') + (int) ($right[$index] ?? '0') + $carry;
            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $result .= (string) $carry;
        }

        return strrev($result);
    }

    /**
     * Subtract one unsigned digit string from another, borrowing from the least significant end.
     *
     * The caller has to have established that the left magnitude is at least the right one; a smaller
     * left operand drops the final borrow and yields a wrong answer rather than an error, which is why
     * `add()` compares the two magnitudes before deciding which way round to call this.
     *
     * @param   string  $left   Minuend digits, no smaller in magnitude than `$right`.
     * @param   string  $right  Subtrahend digits.
     *
     * @return  string  The difference with leading zeros removed, `'0'` when the two magnitudes match.
     *
     * @since   2.0.0
     */
    private static function subtractAbs(string $left, string $right): string
    {
        $left = strrev($left);
        $right = strrev($right);
        $borrow = 0;
        $result = '';
        for ($index = 0; $index < strlen($left); ++$index) {
            $digit = (int) $left[$index] - (int) ($right[$index] ?? '0') - $borrow;
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result .= (string) $digit;
        }

        $result = ltrim(strrev($result), '0');

        return $result === '' ? '0' : $result;
    }

    /**
     * Long-divide one unsigned digit string by another, taking in one numerator digit at a time.
     *
     * @param   string  $numerator    Dividend digits.
     * @param   string  $denominator  Divisor digits, which the caller has already established is not
     *          zero — a zero divisor would leave the subtraction loop spinning forever.
     *
     * @return  array{0: string, 1: string}  The quotient digits and the undivided remainder, each with
     *          leading zeros removed and written as `'0'` when nothing is left.
     *
     * @since   2.0.0
     */
    private static function divideAbs(string $numerator, string $denominator): array
    {
        $quotient = '';
        $remainder = '0';
        $numerator = ltrim($numerator, '0');
        $numerator = $numerator === '' ? '0' : $numerator;
        foreach (str_split($numerator) as $digit) {
            $remainder = ltrim(($remainder === '0' ? '' : $remainder) . $digit, '0');
            $remainder = $remainder === '' ? '0' : $remainder;
            $value = 0;
            while (self::compareAbs($remainder, $denominator) >= 0) {
                $remainder = self::subtractAbs($remainder, $denominator);
                ++$value;
            }
            $quotient .= (string) $value;
        }

        $quotient = ltrim($quotient, '0');

        return [$quotient === '' ? '0' : $quotient, $remainder];
    }
}
