<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/** Exact base-10 arithmetic used by definition formulas without PHP floats. */
final readonly class DecimalValue
{
    private const MAX_DIGITS = 4096;

    private function __construct(private bool $negative, private string $digits, private int $scale)
    {
    }

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

    public function subtract(self $other): self
    {
        return $this->add(new self(!$other->negative && $other->digits !== '0', $other->digits, $other->scale));
    }

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

    public function compare(self $other): int
    {
        if ($this->negative !== $other->negative) {
            return $this->negative ? -1 : 1;
        }
        [$left, $right] = $this->aligned($other);
        $comparison = self::compareAbs($left, $right);

        return $this->negative ? -$comparison : $comparison;
    }

    public function value(): string
    {
        $digits = $this->digits;
        if ($this->scale > 0) {
            $digits = str_pad($digits, $this->scale + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$this->scale) . '.' . substr($digits, -$this->scale);
        }

        return ($this->negative ? '-' : '') . $digits;
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function aligned(self $other): array
    {
        $scale = max($this->scale, $other->scale);

        return [
            $this->digits . str_repeat('0', $scale - $this->scale),
            $other->digits . str_repeat('0', $scale - $other->scale),
            $scale,
        ];
    }

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

    private static function compareAbs(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return $left <=> $right;
    }

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

    /** Subtracts right from left, where left is greater than or equal to right. */
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

        return ltrim(strrev($result), '0') ?: '0';
    }

    /** @return array{0: string, 1: string} */
    private static function divideAbs(string $numerator, string $denominator): array
    {
        $quotient = '';
        $remainder = '0';
        foreach (str_split(ltrim($numerator, '0') ?: '0') as $digit) {
            $remainder = ltrim(($remainder === '0' ? '' : $remainder) . $digit, '0') ?: '0';
            $value = 0;
            while (self::compareAbs($remainder, $denominator) >= 0) {
                $remainder = self::subtractAbs($remainder, $denominator);
                ++$value;
            }
            $quotient .= (string) $value;
        }

        return [ltrim($quotient, '0') ?: '0', $remainder];
    }
}
