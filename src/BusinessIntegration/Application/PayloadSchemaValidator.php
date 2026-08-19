<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use InvalidArgumentException;

/**
 * Deterministic validator for the bounded JSON Schema subset integration contributions may publish.
 *
 * The subset covers object properties, required members, arrays, scalar types, enums and common scalar
 * bounds. References, combinators and remote schemas are deliberately absent, keeping validation local,
 * finite and independent of extension code or network access.
 *
 * @since  2.0.0
 */
final class PayloadSchemaValidator
{
    /**
     * Schema keywords accepted by the bounded payload validator.
     *
     * @var    list<string>  Closed schema-key vocabulary.
     * @since  2.0.0
     */
    private const array KEYS = [
        'type', 'properties', 'required', 'additionalProperties', 'items', 'enum',
        'minLength', 'maxLength', 'minimum', 'maximum', 'minItems', 'maxItems', 'pattern',
    ];

    /**
     * Validate a schema itself before it joins a runtime registry.
     *
     * @param   array<string, mixed>  $schema  Declarative schema object.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the schema uses unsupported or malformed declarations.
     *
     * @since   2.0.0
     */
    public function assertSchema(array $schema): void
    {
        $this->validateSchema($schema, 1);
    }

    /**
     * Validate one payload against a previously accepted schema.
     *
     * @param   array<string, mixed>  $schema   Declarative schema object.
     * @param   array<string, mixed>  $payload  Event or job payload.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the payload violates the contract.
     *
     * @since   2.0.0
     */
    public function assertPayload(array $schema, array $payload): void
    {
        $this->validateValue($schema, $payload, '$');
    }

    /**
     * Validate a bounded contribution payload schema recursively.
     *
     * @param   array<string, mixed>  $schema  Bounded schema object being checked recursively.
     * @param   int                   $depth   Current recursive depth used to enforce payload bounds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateSchema(array $schema, int $depth): void
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('A payload schema exceeds the maximum nesting depth.');
        }
        $unknown = array_diff(array_keys($schema), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('A payload schema contains unsupported keywords.');
        }
        $type = $schema['type'] ?? null;
        if (
            $type !== null && (!is_string($type) || !in_array($type, [
            'object', 'array', 'string', 'integer', 'number', 'boolean', 'null',
            ], true))
        ) {
            throw new InvalidArgumentException('A payload schema type is invalid.');
        }
        if (isset($schema['properties'])) {
            if (!is_array($schema['properties']) || array_is_list($schema['properties'])) {
                throw new InvalidArgumentException('Schema properties must be an object.');
            }
            foreach ($schema['properties'] as $name => $child) {
                if (!is_string($name) || !is_array($child) || ($child !== [] && array_is_list($child))) {
                    throw new InvalidArgumentException('A schema property declaration is invalid.');
                }
                /** @var array<string, mixed> $child */
                $this->validateSchema($child, $depth + 1);
            }
        }
        if (isset($schema['items'])) {
            $items = $schema['items'];
            if (!is_array($items) || ($items !== [] && array_is_list($items))) {
                throw new InvalidArgumentException('Schema items must be an object.');
            }
            /** @var array<string, mixed> $items */
            $this->validateSchema($items, $depth + 1);
        }
        if (isset($schema['required'])) {
            if (!is_array($schema['required']) || !array_is_list($schema['required'])) {
                throw new InvalidArgumentException('Schema required must be a list.');
            }
            foreach ($schema['required'] as $required) {
                if (!is_string($required) || $required === '') {
                    throw new InvalidArgumentException('A required property name is invalid.');
                }
            }
        }
        if (isset($schema['additionalProperties']) && !is_bool($schema['additionalProperties'])) {
            throw new InvalidArgumentException('Schema additionalProperties must be boolean.');
        }
        if (isset($schema['enum']) && (!is_array($schema['enum']) || !array_is_list($schema['enum']))) {
            throw new InvalidArgumentException('Schema enum must be a list.');
        }
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $bound) {
            if (isset($schema[$bound]) && (!is_int($schema[$bound]) || $schema[$bound] < 0)) {
                throw new InvalidArgumentException(sprintf('Schema %s must be a non-negative integer.', $bound));
            }
        }
        foreach (['minimum', 'maximum'] as $bound) {
            if (isset($schema[$bound]) && !is_int($schema[$bound]) && !is_float($schema[$bound])) {
                throw new InvalidArgumentException(sprintf('Schema %s must be numeric.', $bound));
            }
        }
        if (isset($schema['pattern'])) {
            if (!is_string($schema['pattern']) || @preg_match($schema['pattern'], '') === false) {
                throw new InvalidArgumentException('Schema pattern must be a valid delimited regular expression.');
            }
        }
    }

    /**
     * Validate a payload value against the declared schema recursively.
     *
     * @param   array<string, mixed>  $schema  Bounded schema object being checked recursively.
     * @param   mixed                 $value   Candidate value being validated or normalized.
     * @param   string                $path    Declared one-hop field path to split and validate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateValue(array $schema, mixed $value, string $path): void
    {
        $type = $schema['type'] ?? null;
        if (is_string($type) && !$this->matchesType($type, $value)) {
            throw new InvalidArgumentException(sprintf('Payload value %s must be %s.', $path, $type));
        }
        $enum = $schema['enum'] ?? null;
        if ($enum !== null) {
            if (!is_array($enum) || !array_is_list($enum)) {
                throw new InvalidArgumentException('Schema enum must be a list.');
            }
            if (!in_array($value, $enum, true)) {
                throw new InvalidArgumentException(sprintf('Payload value %s is outside its enum.', $path));
            }
        }
        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                throw new InvalidArgumentException(sprintf('Payload string %s is too short.', $path));
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                throw new InvalidArgumentException(sprintf('Payload string %s is too long.', $path));
            }
            $pattern = $schema['pattern'] ?? null;
            if ($pattern !== null) {
                if (!is_string($pattern)) {
                    throw new InvalidArgumentException('Schema pattern must be a valid delimited regular expression.');
                }
                if (preg_match($pattern, $value) !== 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Payload string %s does not match its pattern.',
                        $path,
                    ));
                }
            }
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                throw new InvalidArgumentException(sprintf('Payload number %s is below its minimum.', $path));
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                throw new InvalidArgumentException(sprintf('Payload number %s is above its maximum.', $path));
            }
        }
        if (!is_array($value)) {
            return;
        }
        if (array_is_list($value)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                throw new InvalidArgumentException(sprintf('Payload array %s has too few items.', $path));
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                throw new InvalidArgumentException(sprintf('Payload array %s has too many items.', $path));
            }
            $items = $schema['items'] ?? null;
            if ($items !== null) {
                if (!is_array($items) || ($items !== [] && array_is_list($items))) {
                    throw new InvalidArgumentException('Schema items must be an object.');
                }
                $itemSchema = [];
                foreach ($items as $key => $member) {
                    if (!is_string($key)) {
                        throw new InvalidArgumentException('Schema items must be an object.');
                    }
                    $itemSchema[$key] = $member;
                }
                foreach ($value as $index => $item) {
                    $this->validateValue($itemSchema, $item, sprintf('%s[%d]', $path, $index));
                }
            }
            return;
        }
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
            throw new InvalidArgumentException('Schema properties must be an object.');
        }
        /** @var array<string, mixed> $properties */
        $requiredFields = $schema['required'] ?? [];
        if (!is_array($requiredFields) || !array_is_list($requiredFields)) {
            throw new InvalidArgumentException('Schema required must be a list.');
        }
        foreach ($requiredFields as $required) {
            if (is_string($required) && !array_key_exists($required, $value)) {
                throw new InvalidArgumentException(sprintf(
                    'Payload object %s lacks required property %s.',
                    $path,
                    $required,
                ));
            }
        }
        foreach ($value as $name => $item) {
            $child = $properties[$name] ?? null;
            if (!is_array($child)) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    throw new InvalidArgumentException(sprintf(
                        'Payload object %s has unknown property %s.',
                        $path,
                        $name,
                    ));
                }
                continue;
            }
            if ($child !== [] && array_is_list($child)) {
                throw new InvalidArgumentException('A schema property declaration is invalid.');
            }
            /** @var array<string, mixed> $child */
            $this->validateValue($child, $item, $path . '.' . $name);
        }
    }

    /**
     * Determine whether a value matches the declared schema type.
     *
     * @param   string  $type   Declared schema type to compare.
     * @param   mixed   $value  Candidate value being validated or normalized.
     *
     * @return  bool  Whether the candidate value matches the declared schema type.
     *
     * @since   2.0.0
     */
    private function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }
}
