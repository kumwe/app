<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\MoneyValue;
use Kumwe\CMS\BusinessRecord\Domain\QuantityValue;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\CMS\BusinessRecord\Domain\ZonedDateTimeValue;
use Ramsey\Uuid\Uuid;

final readonly class RecordRuleValidator
{
    public function __construct(private RecordValueCodec $codec)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws BusinessRecordValidationFailed
     */
    public function create(
        EntityTypeDefinition $definition,
        array $input,
        string $siteIdentifier,
        string $recordKey,
        string $recordId,
    ): array {
        $violations = [];
        $fields = $this->fields($definition);
        foreach (array_keys($input) as $handle) {
            if (!isset($fields[$handle])) {
                $violations[] = new ValidationViolation($handle, 'unknown', 'The field is not defined.');
            }
        }

        $values = [];
        foreach ($fields as $field) {
            $isIdentity = in_array($field->type, ['core.uuid', 'core.reference_identity'], true);
            if ($isIdentity) {
                $values[$field->handle] = $recordId;
                continue;
            }
            if ($field->computed || $field->formula !== null) {
                if (array_key_exists($field->handle, $input)) {
                    $violations[] = new ValidationViolation(
                        $field->handle,
                        'read_only',
                        'A computed field cannot be supplied by a caller.',
                    );
                }
                continue;
            }
            if (($field->serverOnly || $field->readOnly) && array_key_exists($field->handle, $input)) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'read_only',
                    'A server-only or read-only field cannot be supplied by a caller.',
                );
                continue;
            }
            $present = array_key_exists($field->handle, $input);
            $raw = $present ? $input[$field->handle] : $field->default;
            if (!$present && $raw === null && !$field->required) {
                $values[$field->handle] = null;
                continue;
            }
            $this->normalizeField(
                $field,
                $raw,
                $siteIdentifier,
                $definition->id,
                $recordKey,
                $values,
                $violations,
            );
        }
        $this->compute($definition, $siteIdentifier, $recordKey, $values, $violations);
        $this->validate($definition, $values, $violations);
        $this->throwIfInvalid($violations);

        return $values;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     * @throws BusinessRecordValidationFailed
     */
    public function update(
        EntityTypeDefinition $definition,
        array $current,
        array $patch,
        string $siteIdentifier,
        string $recordKey,
        string $recordId,
    ): array {
        $violations = [];
        $fields = $this->fields($definition);
        $values = $current;
        foreach ($patch as $handle => $raw) {
            $field = $fields[$handle] ?? null;
            if ($field === null) {
                $violations[] = new ValidationViolation($handle, 'unknown', 'The field is not defined.');
                continue;
            }
            if (
                in_array($field->type, ['core.uuid', 'core.reference_identity'], true)
                || $field->immutableAfterCreate
            ) {
                if (($current[$handle] ?? null) !== $raw) {
                    $violations[] = new ValidationViolation(
                        $handle,
                        'immutable',
                        'An immutable field cannot change after creation.',
                    );
                }
                continue;
            }
            if ($field->serverOnly || $field->readOnly || $field->computed || $field->formula !== null) {
                $violations[] = new ValidationViolation(
                    $handle,
                    'read_only',
                    'A server-only, read-only, or computed field cannot be changed by a caller.',
                );
                continue;
            }
            if ($field->editabilityCondition !== null) {
                try {
                    if ($field->editabilityCondition->evaluate($this->formulaValues($current)) !== true) {
                        $violations[] = new ValidationViolation(
                            $handle,
                            'not_editable',
                            'The field editability condition rejected this change.',
                        );
                        continue;
                    }
                } catch (InvalidArgumentException) {
                    $violations[] = new ValidationViolation(
                        $handle,
                        'condition_failed',
                        'The field editability condition could not be evaluated.',
                    );
                    continue;
                }
            }
            $this->normalizeField(
                $field,
                $raw,
                $siteIdentifier,
                $definition->id,
                $recordKey,
                $values,
                $violations,
            );
        }
        $this->compute($definition, $siteIdentifier, $recordKey, $values, $violations);
        $this->validate($definition, $values, $violations);
        $this->throwIfInvalid($violations);

        return $values;
    }

    /**
     * Reconstitutes virtual formulas after a typed row is decoded.
     *
     * @param array<string, mixed> $storedValues
     * @return array<string, mixed>
     */
    public function materialize(
        EntityTypeDefinition $definition,
        array $storedValues,
        string $siteIdentifier,
        string $recordKey,
    ): array {
        $violations = [];
        $this->compute($definition, $siteIdentifier, $recordKey, $storedValues, $violations, false);
        $this->throwIfInvalid($violations);

        return $storedValues;
    }

    /**
     * Validates a decoded stored row against a newly published definition before schema repinning.
     * Stored computations are recomputed and all normalizers, validators, and invariants run again.
     *
     * @param array<string, mixed> $storedValues
     * @return array<string, mixed>
     */
    public function repin(
        EntityTypeDefinition $definition,
        array $storedValues,
        string $siteIdentifier,
        string $recordKey,
        string $recordId,
    ): array {
        $violations = [];
        $values = [];
        foreach ($definition->fields() as $field) {
            if ($field->type === 'core.ordered_lines') {
                continue;
            }
            if (in_array($field->type, ['core.uuid', 'core.reference_identity'], true)) {
                $violationCount = count($violations);
                $this->normalizeField(
                    $field,
                    $recordId,
                    $siteIdentifier,
                    $definition->id,
                    $recordKey,
                    $values,
                    $violations,
                );
                if (count($violations) === $violationCount && $values[$field->handle] !== $recordId) {
                    $violations[] = new ValidationViolation(
                        $field->handle,
                        'identity_normalization',
                        'A target normalizer would change the immutable record identity.',
                    );
                }
                $values[$field->handle] = $recordId;
                continue;
            }
            if ($field->formula !== null) {
                continue;
            }
            $this->normalizeField(
                $field,
                $storedValues[$field->handle] ?? null,
                $siteIdentifier,
                $definition->id,
                $recordKey,
                $values,
                $violations,
            );
        }
        $this->compute($definition, $siteIdentifier, $recordKey, $values, $violations);
        $this->validate($definition, $values, $violations);
        $this->throwIfInvalid($violations);

        return $values;
    }

    /** @return array<string, FieldDefinition> */
    private function fields(EntityTypeDefinition $definition): array
    {
        $fields = [];
        foreach ($definition->fields() as $field) {
            $fields[$field->handle] = $field;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<ValidationViolation> $violations
     */
    private function normalizeField(
        FieldDefinition $field,
        mixed $raw,
        string $siteIdentifier,
        string $definitionId,
        string $recordKey,
        array &$values,
        array &$violations,
    ): void {
        if ($raw === null) {
            $values[$field->handle] = null;
            return;
        }
        try {
            $values[$field->handle] = $this->codec->normalize(
                $field,
                $raw,
                $siteIdentifier,
                $definitionId,
                $recordKey,
            );
        } catch (InvalidArgumentException $exception) {
            $violations[] = new ValidationViolation($field->handle, 'invalid_type', $exception->getMessage());
        }
    }

    /**
     * Re-evaluating every formula is deterministic and also invalidates every stored computation dependency.
     *
     * @param array<string, mixed> $values
     * @param list<ValidationViolation> $violations
     */
    private function compute(
        EntityTypeDefinition $definition,
        string $siteIdentifier,
        string $recordKey,
        array &$values,
        array &$violations,
        bool $requireAll = true,
    ): void {
        $pending = [];
        foreach ($definition->fields() as $field) {
            if ($field->formula !== null) {
                $pending[$field->handle] = $field;
            }
        }
        for ($pass = 0; $pending !== [] && $pass <= count($pending); ++$pass) {
            $advanced = false;
            foreach ($pending as $handle => $field) {
                foreach ($field->formula?->dependencies() ?? [] as $dependency) {
                    if (!array_key_exists($dependency, $values)) {
                        continue 2;
                    }
                }
                try {
                    $raw = $field->formula?->evaluate($this->formulaValues($values));
                    $values[$handle] = $this->codec->normalize(
                        $field,
                        $raw,
                        $siteIdentifier,
                        $definition->id,
                        $recordKey,
                    );
                    unset($pending[$handle]);
                    $advanced = true;
                } catch (InvalidArgumentException $exception) {
                    $violations[] = new ValidationViolation($handle, 'formula_failed', $exception->getMessage());
                    unset($pending[$handle]);
                }
            }
            if (!$advanced) {
                break;
            }
        }
        if ($requireAll) {
            foreach (array_keys($pending) as $handle) {
                $violations[] = new ValidationViolation(
                    $handle,
                    'formula_dependency',
                    'A stored computation dependency is unavailable.',
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param list<ValidationViolation> $violations
     */
    private function validate(EntityTypeDefinition $definition, array $values, array &$violations): void
    {
        foreach ($definition->fields() as $field) {
            $value = $values[$field->handle] ?? null;
            if ($field->required && $value === null) {
                $violations[] = new ValidationViolation($field->handle, 'required', 'The field is required.');
                continue;
            }
            if (!$field->nullable && $value === null) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'not_nullable',
                    'The field cannot be null.',
                );
                continue;
            }
            if ($value === null) {
                continue;
            }
            foreach ($field->validators as $validator) {
                $violation = $this->validator($field, $value, $validator, $values);
                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }
        $this->validateInvariants($definition, $values, $violations);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<ValidationViolation> $violations
     */
    private function validateInvariants(
        EntityTypeDefinition $definition,
        array $values,
        array &$violations,
    ): void {
        foreach ($definition->recordInvariants() as $invariant) {
            try {
                $satisfied = $invariant->isSatisfied($this->formulaValues($values));
            } catch (InvalidArgumentException $exception) {
                $violations[] = new ValidationViolation(
                    $invariant->handle,
                    'invariant_invalid',
                    $exception->getMessage(),
                );
                continue;
            }
            if (!$satisfied) {
                $violations[] = new ValidationViolation(
                    $invariant->handle,
                    'invariant.' . $invariant->handle,
                    $invariant->message,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $validator
     * @param array<string, mixed> $values
     */
    private function validator(
        FieldDefinition $field,
        mixed $value,
        array $validator,
        array $values,
    ): ?ValidationViolation {
        $rule = $validator['rule'] ?? null;
        if (!is_string($rule)) {
            return $this->violation($field, 'validator_invalid', 'A validator has no registered rule.');
        }
        try {
            $valid = match ($rule) {
                'min_length' => is_string($value)
                    && mb_strlen($value) >= $this->validatorInt($validator, 'value', 0, 1_000_000),
                'max_length' => is_string($value)
                    && mb_strlen($value) <= $this->validatorInt($validator, 'value', 0, 1_000_000),
                'pattern' => is_string($value) && $this->pattern($value, $validator),
                'min' => $this->compare($value, $this->validatorScalar($validator, 'value'), $field) >= 0,
                'max' => $this->compare($value, $this->validatorScalar($validator, 'value'), $field) <= 0,
                'one_of' => in_array(
                    RecordValueGuard::canonical($value),
                    $this->validatorList($validator, 'value'),
                    true,
                ),
                'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'url' => is_string($value) && $this->validHttpUrl($value),
                'uuid' => is_string($value) && Uuid::isValid($value),
                'integer' => is_int($value),
                'decimal' => $value instanceof ExactDecimal,
                default => throw new InvalidArgumentException('An unregistered validator rule was requested.'),
            };
        } catch (InvalidArgumentException $exception) {
            return $this->violation($field, 'validator_invalid', $exception->getMessage());
        }
        if ($valid) {
            return null;
        }
        return $this->violation($field, $rule, 'The field failed its ' . $rule . ' validation rule.');
    }

    /** @param array<string, mixed> $validator */
    private function pattern(string $value, array $validator): bool
    {
        $expression = $validator['value'] ?? null;
        if (!is_string($expression) || $expression === '' || strlen($expression) > 512) {
            throw new InvalidArgumentException('A pattern validator requires a bounded expression.');
        }
        $pattern = '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)(?:' . str_replace('~', '\\~', $expression) . ')~uD';
        $result = preg_match($pattern, $value);
        if ($result === false) {
            throw new InvalidArgumentException('A pattern validator is invalid or exceeded its runtime limit.');
        }

        return $result === 1;
    }

    private function compare(mixed $left, mixed $right, FieldDefinition $field): int
    {
        if ($left instanceof ExactDecimal) {
            $normalized = $this->codec->normalize($field, $right, '', '', '');
            if (!$normalized instanceof ExactDecimal) {
                throw new InvalidArgumentException('An exact validator comparison is incompatible.');
            }
            return $left->compare($normalized);
        }
        if (is_int($left) && is_string($right) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $right) === 1) {
            $integer = filter_var($right, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $left <=> $integer;
            }
        }
        if (is_string($left) && is_string($right)) {
            return $left <=> $right;
        }
        throw new InvalidArgumentException('A range validator value is incompatible with its field.');
    }

    /** @param array<string, mixed> $validator */
    private function validatorInt(array $validator, string $key, int $minimum, int $maximum): int
    {
        $value = $validator[$key] ?? null;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('A validator integer parameter is outside its safe bound.');
        }

        return $value;
    }

    /** @param array<string, mixed> $validator */
    private function validatorScalar(array $validator, string $key): bool|int|string|null
    {
        $value = $validator[$key] ?? null;
        if (is_float($value) || (!is_scalar($value) && $value !== null)) {
            throw new InvalidArgumentException('A validator scalar parameter is invalid.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $validator
     * @return list<bool|int|string|null>
     */
    private function validatorList(array $validator, string $key): array
    {
        $values = $validator[$key] ?? null;
        if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > 256) {
            throw new InvalidArgumentException('A validator list parameter is empty or unbounded.');
        }
        $result = [];
        foreach ($values as $value) {
            if (is_float($value) || (!is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('A validator list contains an invalid value.');
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, bool|float|int|string|null>
     */
    private function formulaValues(array $values): array
    {
        $result = [];
        foreach ($values as $handle => $value) {
            $result[$handle] = match (true) {
                $value instanceof ExactDecimal => $value->value(),
                $value instanceof DateTimeImmutable => $value->format('Y-m-d\TH:i:s.uP'),
                $value instanceof ZonedDateTimeValue => $value->instant->format('Y-m-d\TH:i:s.u\Z'),
                $value instanceof MoneyValue, $value instanceof QuantityValue => null,
                is_scalar($value), $value === null => $value,
                default => null,
            };
        }

        return $result;
    }

    private function violation(FieldDefinition $field, string $code, string $message): ValidationViolation
    {
        return new ValidationViolation($field->handle, $code, $message);
    }

    private function validHttpUrl(string $value): bool
    {
        $parts = parse_url($value);
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true);
    }

    /** @param list<ValidationViolation> $violations */
    private function throwIfInvalid(array $violations): void
    {
        if ($violations !== []) {
            throw new BusinessRecordValidationFailed($violations);
        }
    }
}
