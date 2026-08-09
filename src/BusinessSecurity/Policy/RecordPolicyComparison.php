<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Policy;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Compare one declared record field with one bounded, exactly typed literal.
 *
 * @since  2.0.0
 */
final readonly class RecordPolicyComparison implements RecordPolicyPredicate
{
    /**
     * Create a typed comparison between one record field and one literal.
     *
     * @param   string                          $field      Stable business-field handle to inspect.
     * @param   RecordPolicyComparisonOperator  $operator   Portable comparison to perform.
     * @param   RecordPolicyValueType           $valueType  Exact type of both field and literal.
     * @param   string|int|bool                  $value      Bounded literal; decimal values are canonical strings.
     *
     * @throws  InvalidArgumentException  When the handle or literal contradicts the policy type contract.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $field,
        public RecordPolicyComparisonOperator $operator,
        public RecordPolicyValueType $valueType,
        public string|int|bool $value,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field) !== 1) {
            throw new InvalidArgumentException('A record-policy field handle is invalid.');
        }
        if (!$this->literalMatchesType()) {
            throw new InvalidArgumentException('A record-policy literal does not match its declared type.');
        }
        if (is_string($value) && strlen($value) > 4096) {
            throw new InvalidArgumentException('A record-policy literal exceeds 4096 bytes.');
        }
        if (
            $valueType === RecordPolicyValueType::Decimal
            && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', (string) $value) !== 1
        ) {
            throw new InvalidArgumentException('A record-policy decimal must use canonical base-10 notation.');
        }
        if (
            $valueType === RecordPolicyValueType::Temporal
            && !$this->temporalLiteralIsCanonical()
        ) {
            throw new InvalidArgumentException('A record-policy temporal literal is not canonical.');
        }
        if (
            in_array($valueType, [RecordPolicyValueType::String, RecordPolicyValueType::Boolean], true)
            && !in_array($operator, [
                RecordPolicyComparisonOperator::Equal,
                RecordPolicyComparisonOperator::NotEqual,
            ], true)
        ) {
            throw new InvalidArgumentException('A string or boolean record-policy field only supports equality.');
        }
    }

    /**
     * Return the deterministic comparison document used for policy digests.
     *
     * @return  array<string, mixed>  Canonical comparison predicate document.
     *
     * @since  2.0.0
     */
    public function toArray(): array
    {
        return [
            'type' => 'comparison',
            'field' => $this->field,
            'operator' => $this->operator->value,
            'value_type' => $this->valueType->value,
            'value' => $this->value,
        ];
    }

    /**
     * Count this leaf as one policy operation.
     *
     * @return  int  Always one.
     *
     * @since  2.0.0
     */
    public function operationCount(): int
    {
        return 1;
    }

    /**
     * Measure this leaf as a one-level tree.
     *
     * @return  int  Always one.
     *
     * @since  2.0.0
     */
    public function depth(): int
    {
        return 1;
    }

    /**
     * Check the runtime union against its declared policy type.
     *
     * @return  bool  True when the literal can be compared without coercion.
     *
     * @since  2.0.0
     */
    private function literalMatchesType(): bool
    {
        return match ($this->valueType) {
            RecordPolicyValueType::String,
            RecordPolicyValueType::Decimal,
            RecordPolicyValueType::Temporal => is_string($this->value),
            RecordPolicyValueType::Integer => is_int($this->value),
            RecordPolicyValueType::Boolean => is_bool($this->value),
        };
    }

    /**
     * Validate both the shape and calendar value of one temporal literal.
     *
     * @return  bool  True only for an exact date, local time, or six-digit UTC instant.
     *
     * @since   2.0.0
     */
    private function temporalLiteralIsCanonical(): bool
    {
        if (!is_string($this->value)) {
            return false;
        }
        $format = match (true) {
            preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $this->value) === 1 => '!Y-m-d',
            preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $this->value) === 1 => '!H:i:s',
            preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}$/D', $this->value) === 1 => '!H:i:s.u',
            preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z$/D',
                $this->value,
            ) === 1 => '!Y-m-d\TH:i:s.u\Z',
            default => null,
        };
        if ($format === null) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat($format, $this->value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && $parsed->format(ltrim($format, '!')) === $this->value
            && (!str_contains($format, 'Y') || (int) $parsed->format('Y') >= 1000)
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
