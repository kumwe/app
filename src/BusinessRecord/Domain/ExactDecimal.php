<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;
use Stringable;

/** A fixed-scale base-10 value. PHP floats are deliberately outside this contract. */
final readonly class ExactDecimal implements Stringable
{
    private function __construct(
        private string $value,
        public int $precision,
        public int $scale,
    ) {
    }

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

    public static function fromInt(int $value, int $precision, int $scale): self
    {
        return self::fromString((string) $value, $precision, $scale);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function compare(self $other): int
    {
        if ($this->scale !== $other->scale) {
            throw new InvalidArgumentException('Exact decimals with different scales cannot be compared directly.');
        }

        return self::compareCanonical($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

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
