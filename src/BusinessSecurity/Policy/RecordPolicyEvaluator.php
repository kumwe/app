<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Policy;

use DateTimeImmutable;
use DateTimeZone;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparison;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparisonOperator;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyPredicate;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyValueType;
use Stringable;

/**
 * In-memory interpreter for the same closed record-policy AST persistence compilers consume.
 *
 * @since  2.0.0
 */
final class RecordPolicyEvaluator
{
    /**
     * Apply allow-first, deny-overrides semantics to one record.
     *
     * @param   RecordPolicySet       $policy  Validated policy set to interpret.
     * @param   array<string, mixed>  $values  Record values keyed by field handle.
     *
     * @return  bool  True only when at least one allow and no deny evaluates true.
     *
     * @since   2.0.0
     */
    public function allows(RecordPolicySet $policy, array $values): bool
    {
        if ($policy->allows === []) {
            return false;
        }
        foreach ($policy->denies as $deny) {
            if ($this->evaluate($deny, $values)) {
                return false;
            }
        }
        foreach ($policy->allows as $allow) {
            if ($this->evaluate($allow, $values)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate one validated predicate. Missing fields have SQL-null semantics.
     *
     * @param   RecordPolicyPredicate  $predicate  Predicate node to interpret.
     * @param   array<string, mixed>   $values     Record values keyed by field handle.
     *
     * @return  bool  Definite truth value; comparisons with null or a mismatched runtime type are false.
     *
     * @since   2.0.0
     */
    public function evaluate(RecordPolicyPredicate $predicate, array $values): bool
    {
        if ($predicate instanceof RecordPolicyConstant) {
            return $predicate->value;
        }
        if ($predicate instanceof RecordPolicyNullCheck) {
            $null = !array_key_exists($predicate->field, $values) || $values[$predicate->field] === null;

            return $predicate->isNull ? $null : !$null;
        }
        if ($predicate instanceof RecordPolicyBoolean) {
            foreach ($predicate->children as $child) {
                $result = $this->evaluate($child, $values);
                if ($predicate->operator === RecordPolicyBooleanOperator::All && !$result) {
                    return false;
                }
                if ($predicate->operator === RecordPolicyBooleanOperator::Any && $result) {
                    return true;
                }
            }

            return $predicate->operator === RecordPolicyBooleanOperator::All;
        }
        if (!$predicate instanceof RecordPolicyComparison) {
            return false;
        }
        $actual = $values[$predicate->field] ?? null;
        $comparison = $this->compare($predicate->valueType, $actual, $predicate->value);
        if ($comparison === null) {
            return false;
        }

        return match ($predicate->operator) {
            RecordPolicyComparisonOperator::Equal => $comparison === 0,
            RecordPolicyComparisonOperator::NotEqual => $comparison !== 0,
            RecordPolicyComparisonOperator::LessThan => $comparison < 0,
            RecordPolicyComparisonOperator::LessThanOrEqual => $comparison <= 0,
            RecordPolicyComparisonOperator::GreaterThan => $comparison > 0,
            RecordPolicyComparisonOperator::GreaterThanOrEqual => $comparison >= 0,
        };
    }

    /**
     * Compare two values without type coercion or floating point.
     *
     * @param   RecordPolicyValueType  $type      Declared portable comparison domain.
     * @param   mixed                  $actual    Stored record value.
     * @param   string|int|bool        $expected  Canonical policy literal.
     *
     * @return  int|null  Three-way comparison, or null when the stored value has another type.
     *
     * @since   2.0.0
     */
    private function compare(RecordPolicyValueType $type, mixed $actual, string|int|bool $expected): ?int
    {
        return match ($type) {
            RecordPolicyValueType::String => is_string($actual) ? $actual <=> (string) $expected : null,
            RecordPolicyValueType::Integer => is_int($actual) ? $actual <=> (int) $expected : null,
            RecordPolicyValueType::Boolean => is_bool($actual) ? $actual <=> (bool) $expected : null,
            RecordPolicyValueType::Decimal => $this->decimal($actual, (string) $expected),
            RecordPolicyValueType::Temporal => $this->temporal($actual, (string) $expected),
        };
    }

    /**
     * Compare a normalized temporal domain value with its canonical policy literal.
     *
     * @param   mixed   $actual    Stored temporal value.
     * @param   string  $expected  Canonical date, time, or UTC instant literal.
     *
     * @return  int|null  Exact chronological comparison, or null for another runtime type.
     *
     * @since   2.0.0
     */
    private function temporal(mixed $actual, string $expected): ?int
    {
        if (is_string($actual)) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $actual);
            $errors = DateTimeImmutable::getLastErrors();
            if (
                !$parsed instanceof DateTimeImmutable
                || $parsed->format('Y-m-d\TH:i:s.uP') !== $actual
                || (is_array($errors) && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            ) {
                return null;
            }
            $actual = $parsed;
        }
        if (!$actual instanceof DateTimeImmutable) {
            return null;
        }
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $expected) === 1) {
            $actual = $actual->format('Y-m-d');
        } elseif (preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $expected) === 1) {
            $actual = $actual->format('H:i:s.u');
            $expected .= '.000000';
        } elseif (preg_match('/^[0-9]{2}:/D', $expected) === 1) {
            $actual = $actual->format('H:i:s.u');
        } else {
            $actual = $actual->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
        }

        return $actual <=> $expected;
    }

    /**
     * Compare exact decimal strings by sign and aligned digits.
     *
     * @param   mixed   $actual    Stored exact-decimal value or string representation.
     * @param   string  $expected  Canonical decimal policy literal.
     *
     * @return  int|null  Exact comparison, or null for a non-decimal stored value.
     *
     * @since   2.0.0
     */
    private function decimal(mixed $actual, string $expected): ?int
    {
        if ($actual instanceof Stringable) {
            $actual = (string) $actual;
        }
        if (!is_string($actual) || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $actual) !== 1) {
            return null;
        }
        $parts = static function (string $value): array {
            $negative = str_starts_with($value, '-');
            [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
            $integer = ltrim($integer, '0');
            if ($integer === '') {
                $integer = '0';
            }

            return [$negative, $integer, rtrim($fraction, '0')];
        };
        [$leftNegative, $leftInteger, $leftFraction] = $parts($actual);
        [$rightNegative, $rightInteger, $rightFraction] = $parts($expected);
        if ($leftInteger === '0' && $leftFraction === '') {
            $leftNegative = false;
        }
        if ($rightInteger === '0' && $rightFraction === '') {
            $rightNegative = false;
        }
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $comparison = strlen($leftInteger) <=> strlen($rightInteger);
        if ($comparison === 0) {
            $comparison = $leftInteger <=> $rightInteger;
        }
        if ($comparison === 0) {
            $scale = max(strlen($leftFraction), strlen($rightFraction));
            $comparison = str_pad($leftFraction, $scale, '0') <=> str_pad($rightFraction, $scale, '0');
        }

        return $leftNegative ? -$comparison : $comparison;
    }
}
