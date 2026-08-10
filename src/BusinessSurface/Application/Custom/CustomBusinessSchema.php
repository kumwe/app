<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application\Custom;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * Closed, deterministic JSON Schema subset for one custom business handler contract.
 *
 * The subset has no references, executable formats, regular expressions, floating-point numbers, or
 * open objects. Schema graphs and every collection they can describe are bounded, which makes a signed
 * contract safe to validate during activation and safe to evaluate before and after every handler call.
 *
 * @since  2.0.0
 */
final readonly class CustomBusinessSchema
{
    /**
     * Keywords understood by the custom-handler schema evaluator.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const KEYWORDS = [
        'type', 'title', 'description', 'properties', 'required', 'additionalProperties',
        'items', 'enum', 'const', 'minLength', 'maxLength', 'minimum', 'maximum',
        'minItems', 'maxItems', 'minProperties', 'maxProperties', 'format',
    ];

    /**
     * Supported exact JSON value types.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const TYPES = ['object', 'array', 'string', 'integer', 'boolean', 'null'];

    /**
     * Persistence and audit property names custom contracts may never claim at any nesting depth.
     *
     * Rejecting these at signed-contract admission lets every adapter preserve validated result data
     * exactly instead of applying a transport-specific recursive key filter after handler execution.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private const array RESERVED_PROPERTIES = [
        'actor_id' => true,
        'definition_id' => true,
        'integrity_hash' => true,
        'internal_id' => true,
        'organization_id' => true,
        'record_key' => true,
        'revision_id' => true,
        'site_identifier' => true,
    ];

    /**
     * Canonically key-sorted schema document.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $schema;

    /**
     * Validate and canonicalize one closed object contract.
     *
     * @param   array<string, mixed>  $schema  Candidate schema declared by a signed package.
     *
     * @throws  InvalidArgumentException  When the root is not a closed object schema, a keyword or type is
     *          unsupported, a bound is unsafe, or the graph exceeds eight levels or 256 nodes.
     *
     * @since   2.0.0
     */
    public function __construct(array $schema)
    {
        $nodes = 0;
        self::assertSchema($schema, '$', 0, $nodes);
        if (!in_array('object', self::types($schema, '$'), true)) {
            throw new InvalidArgumentException('A custom business schema root must have object type.');
        }

        try {
            $encoded = json_encode(
                $schema,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $decoded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A custom business schema must be valid exact JSON.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('A custom business schema root must be an object.');
        }
        if (strlen($encoded) > 262_144) {
            throw new InvalidArgumentException('A custom business schema exceeds 262144 bytes.');
        }
        /** @var array<string, mixed> $decoded */
        $this->schema = self::sortKeys($decoded);
    }

    /**
     * Rebuild a contract schema from its manifest representation.
     *
     * @param   mixed  $schema  Decoded manifest member expected to be a JSON object.
     *
     * @return  self  Validated schema contract.
     *
     * @throws  InvalidArgumentException  When the value is not an object or violates the supported subset.
     *
     * @since   2.0.0
     */
    public static function fromArray(mixed $schema): self
    {
        if (!is_array($schema) || array_is_list($schema)) {
            throw new InvalidArgumentException('A custom business schema must be an object.');
        }
        /** @var array<string, mixed> $schema */
        return new self($schema);
    }

    /**
     * Export the canonical schema document used for manifest reconciliation and publication.
     *
     * @return  array<string, mixed>  Recursively key-sorted closed JSON Schema document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return $this->schema;
    }

    /**
     * Validate a bounded object payload against this schema.
     *
     * @param   array<string, mixed>  $payload  Query parameters, command input, or handler result.
     * @param   string                $kind     Payload kind used in stable validation failures.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the payload exceeds shared bounds or violates the schema.
     *
     * @since   2.0.0
     */
    public function assertValid(array $payload, string $kind): void
    {
        CustomBusinessPayload::assertObject($payload, $kind);
        $violations = [];
        $this->validateValue($this->schema, $payload, '$', $violations);
        if ($violations !== []) {
            throw new InvalidArgumentException(sprintf(
                'A custom business %s violates its contract: %s.',
                $kind,
                implode('; ', array_slice($violations, 0, 32)),
            ));
        }
    }

    /**
     * Validate one schema node and recurse through its properties and item schema.
     *
     * @param   array<string, mixed>  $schema  Schema node being checked.
     * @param   string                $path    Stable JSON path naming the node.
     * @param   int                   $depth   Current schema depth.
     * @param   int                   $nodes   Shared schema-node counter.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the node is unknown, ambiguous, open, or unbounded.
     *
     * @since   2.0.0
     */
    private static function assertSchema(array $schema, string $path, int $depth, int &$nodes): void
    {
        ++$nodes;
        if ($schema === [] || array_is_list($schema) || $depth > 8 || $nodes > 256) {
            throw new InvalidArgumentException('A custom business schema exceeds its structural bounds.');
        }
        $unknown = array_diff(array_keys($schema), self::KEYWORDS);
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new InvalidArgumentException(sprintf(
                'A custom business schema contains unsupported keyword %s.',
                $unknown[0],
            ));
        }
        $types = self::types($schema, $path);
        foreach (['title', 'description'] as $key) {
            $value = $schema[$key] ?? null;
            if ($value !== null && (!is_string($value) || strlen($value) > 500)) {
                throw new InvalidArgumentException(sprintf('Custom business schema %s.%s is invalid.', $path, $key));
            }
        }
        self::assertEnumeration($schema, $path);
        self::assertIntegerBounds($schema, $path, 'minLength', 'maxLength', 65_535);
        self::assertIntegerBounds($schema, $path, 'minItems', 'maxItems', 200);
        self::assertIntegerBounds($schema, $path, 'minProperties', 'maxProperties', 128);
        self::assertNumericBounds($schema, $path);
        if (!in_array('integer', $types, true) && (isset($schema['minimum']) || isset($schema['maximum']))) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s has integer-only keywords.', $path));
        }

        if (in_array('object', $types, true)) {
            if (($schema['additionalProperties'] ?? null) !== false) {
                throw new InvalidArgumentException(sprintf(
                    'Custom business schema %s must close additional properties.',
                    $path,
                ));
            }
            self::assertProperties($schema, $path, $depth, $nodes);
        } elseif (
            isset($schema['properties'])
            || isset($schema['required'])
            || isset($schema['additionalProperties'])
        ) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s has object-only keywords.', $path));
        }

        if (in_array('array', $types, true)) {
            $items = $schema['items'] ?? null;
            if (!is_array($items) || array_is_list($items) || !isset($schema['maxItems'])) {
                throw new InvalidArgumentException(sprintf(
                    'Custom business schema %s arrays require an object item schema and maxItems.',
                    $path,
                ));
            }
            /** @var array<string, mixed> $items */
            self::assertSchema($items, $path . '.items', $depth + 1, $nodes);
        } elseif (isset($schema['items']) || isset($schema['minItems']) || isset($schema['maxItems'])) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s has array-only keywords.', $path));
        }

        if (in_array('string', $types, true)) {
            $format = $schema['format'] ?? null;
            if (
                $format !== null && (!is_string($format) || !in_array(
                    $format,
                    ['date', 'date-time', 'email', 'uuid'],
                    true,
                ))
            ) {
                throw new InvalidArgumentException(sprintf('Custom business schema %s format is unsupported.', $path));
            }
        } elseif (isset($schema['minLength']) || isset($schema['maxLength']) || isset($schema['format'])) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s has string-only keywords.', $path));
        }
    }

    /**
     * Read and validate the one or two exact types a schema node admits.
     *
     * A two-item union must contain `null`; arbitrary unions are left out of this subset so a contract
     * author uses one unambiguous primary type and optional nullability.
     *
     * @param   array<string, mixed>  $schema  Schema node carrying `type`.
     * @param   string                $path    Path named in a failure.
     *
     * @return  list<string>  One supported type, or that type together with `null`.
     *
     * @throws  InvalidArgumentException  When the type declaration is missing, unknown, repeated, or ambiguous.
     *
     * @since   2.0.0
     */
    private static function types(array $schema, string $path): array
    {
        $value = $schema['type'] ?? null;
        $types = is_string($value) ? [$value] : $value;
        if (
            !is_array($types)
            || !array_is_list($types)
            || $types === []
            || count($types) > 2
            || count($types) !== count(array_unique($types))
        ) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s type is invalid.', $path));
        }
        foreach ($types as $type) {
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException(sprintf('Custom business schema %s type is unsupported.', $path));
            }
        }
        if (count($types) === 2 && !in_array('null', $types, true)) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s type union is ambiguous.', $path));
        }

        /** @var list<string> $types */
        return $types;
    }

    /**
     * Validate enum and const values as exact bounded JSON data.
     *
     * @param   array<string, mixed>  $schema  Schema node that may declare enum or const.
     * @param   string                $path    Path named in a failure.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an enum is empty, oversized, duplicated, or a value is unsafe.
     *
     * @since   2.0.0
     */
    private static function assertEnumeration(array $schema, string $path): void
    {
        if (isset($schema['enum'])) {
            $values = $schema['enum'];
            if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > 128) {
                throw new InvalidArgumentException(sprintf('Custom business schema %s enum is invalid.', $path));
            }
            $encoded = [];
            foreach ($values as $value) {
                $encodedValue = self::encodedExactValue($value, $path . '.enum');
                if (isset($encoded[$encodedValue])) {
                    throw new InvalidArgumentException(sprintf('Custom business schema %s enum is duplicated.', $path));
                }
                $encoded[$encodedValue] = true;
            }
        }
        if (array_key_exists('const', $schema)) {
            self::encodedExactValue($schema['const'], $path . '.const');
        }
    }

    /**
     * Validate paired non-negative integer bounds and their fixed safety ceiling.
     *
     * @param   array<string, mixed>  $schema   Schema node carrying the optional pair.
     * @param   string                $path     Path named in a failure.
     * @param   string                $minimum  Lower-bound keyword.
     * @param   string                $maximum  Upper-bound keyword.
     * @param   int                   $ceiling  Largest accepted upper bound.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When either bound is invalid, contradictory, or over the ceiling.
     *
     * @since   2.0.0
     */
    private static function assertIntegerBounds(
        array $schema,
        string $path,
        string $minimum,
        string $maximum,
        int $ceiling,
    ): void {
        $min = $schema[$minimum] ?? null;
        $max = $schema[$maximum] ?? null;
        if ($min !== null && (!is_int($min) || $min < 0 || $min > $ceiling)) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s.%s is invalid.', $path, $minimum));
        }
        if ($max !== null && (!is_int($max) || $max < 0 || $max > $ceiling)) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s.%s is invalid.', $path, $maximum));
        }
        if (is_int($min) && is_int($max) && $min > $max) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s bounds are contradictory.', $path));
        }
    }

    /**
     * Validate exact-integer minimum and maximum constraints.
     *
     * @param   array<string, mixed>  $schema  Schema node carrying optional numeric bounds.
     * @param   string                $path    Path named in a failure.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a bound is not an integer or minimum exceeds maximum.
     *
     * @since   2.0.0
     */
    private static function assertNumericBounds(array $schema, string $path): void
    {
        $minimum = $schema['minimum'] ?? null;
        $maximum = $schema['maximum'] ?? null;
        if ($minimum !== null && !is_int($minimum)) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s.minimum must be integer.', $path));
        }
        if ($maximum !== null && !is_int($maximum)) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s.maximum must be integer.', $path));
        }
        if (is_int($minimum) && is_int($maximum) && $minimum > $maximum) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s numeric bounds conflict.', $path));
        }
    }

    /**
     * Validate a closed property map and its required-field list.
     *
     * @param   array<string, mixed>  $schema  Object schema being inspected.
     * @param   string                $path    Path naming the object schema.
     * @param   int                   $depth   Current schema depth.
     * @param   int                   $nodes   Shared schema-node counter.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When properties or required fields are malformed or unbounded.
     *
     * @since   2.0.0
     */
    private static function assertProperties(array $schema, string $path, int $depth, int &$nodes): void
    {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties) || ($properties !== [] && array_is_list($properties)) || count($properties) > 128) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s properties are invalid.', $path));
        }
        foreach ($properties as $name => $child) {
            if (
                !is_string($name)
                || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1
                || isset(self::RESERVED_PROPERTIES[$name])
                || !is_array($child)
                || array_is_list($child)
            ) {
                throw new InvalidArgumentException(sprintf('Custom business schema %s has an unsafe property.', $path));
            }
            /** @var array<string, mixed> $child */
            self::assertSchema($child, $path . '.properties.' . $name, $depth + 1, $nodes);
        }
        $required = $schema['required'] ?? [];
        if (!is_array($required) || !array_is_list($required) || count($required) > 128) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s required list is invalid.', $path));
        }
        $seen = [];
        foreach ($required as $name) {
            if (!is_string($name) || !array_key_exists($name, $properties) || isset($seen[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Custom business schema %s required list is unsafe.',
                    $path,
                ));
            }
            $seen[$name] = true;
        }
    }

    /**
     * Canonically encode one enum or const value after excluding floats, objects, and resources.
     *
     * @param   mixed   $value  Exact value declared by the schema.
     * @param   string  $path   Location used in a failure.
     *
     * @return  string  Deterministic JSON spelling used to spot duplicates.
     *
     * @throws  InvalidArgumentException  When the value cannot be represented as bounded exact JSON.
     *
     * @since   2.0.0
     */
    private static function encodedExactValue(mixed $value, string $path): string
    {
        $nodes = 0;
        self::assertExactValue($value, $path, 0, $nodes);
        try {
            return json_encode(self::sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('Custom business schema %s contains invalid JSON.', $path),
                0,
                $exception,
            );
        }
    }

    /**
     * Bound one enum or const value before canonical encoding.
     *
     * @param   mixed   $value  Value at the current location.
     * @param   string  $path   Schema location named in failures.
     * @param   int     $depth  Current exact-value depth.
     * @param   int     $nodes  Shared number of exact values visited.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is inexact, structurally oversized, or invalid UTF-8.
     *
     * @since   2.0.0
     */
    private static function assertExactValue(mixed $value, string $path, int $depth, int &$nodes): void
    {
        ++$nodes;
        if ($depth > 8 || $nodes > 4096) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s value is unbounded.', $path));
        }
        if (is_string($value)) {
            if (strlen($value) > 65_535 || preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('Custom business schema %s string is invalid.', $path));
            }
            return;
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }
        if (!is_array($value) || count($value) > 200) {
            throw new InvalidArgumentException(sprintf('Custom business schema %s contains an inexact value.', $path));
        }
        foreach ($value as $item) {
            self::assertExactValue($item, $path, $depth + 1, $nodes);
        }
    }

    /**
     * Recursively sort object keys while preserving list order.
     *
     * @param   mixed  $value  Schema or enum value to normalize.
     *
     * @return  mixed  Scalars unchanged and arrays rebuilt deterministically.
     *
     * @since   2.0.0
     */
    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sortKeys(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortKeys($item);
        }
        return $value;
    }

    /**
     * Validate one value against one already-supported schema node.
     *
     * @param   array<string, mixed>  $schema      Schema node to evaluate.
     * @param   mixed                 $value       Exact decoded JSON value at this location.
     * @param   string                $path        Stable JSON path used in violations.
     * @param   list<string>          $violations  Accumulator capped by the public caller at 32 messages.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateValue(array $schema, mixed $value, string $path, array &$violations): void
    {
        if (count($violations) >= 32) {
            return;
        }
        $types = self::types($schema, $path);
        if (!$this->matchesAnyType($types, $value)) {
            $violations[] = $path . ' has the wrong type';
            return;
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $violations[] = $path . ' does not match const';
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $violations[] = $path . ' is not in enum';
        }
        if (is_string($value)) {
            $this->validateString($schema, $value, $path, $violations);
        } elseif (is_int($value)) {
            $minimum = $schema['minimum'] ?? null;
            $maximum = $schema['maximum'] ?? null;
            if (is_int($minimum) && $value < $minimum) {
                $violations[] = $path . ' is below minimum';
            }
            if (is_int($maximum) && $value > $maximum) {
                $violations[] = $path . ' is above maximum';
            }
        } elseif (is_array($value)) {
            $types = self::types($schema, $path);
            if ($value === [] && in_array('object', $types, true) && !in_array('array', $types, true)) {
                $this->validateObject($schema, $value, $path, $violations);
            } elseif (array_is_list($value)) {
                $this->validateList($schema, $value, $path, $violations);
            } else {
                $this->validateObject($schema, $value, $path, $violations);
            }
        }
    }

    /**
     * Decide whether a runtime value matches any declared exact type.
     *
     * @param   list<string>  $types  Supported types declared at the node.
     * @param   mixed         $value  Runtime value to classify.
     *
     * @return  bool  True when at least one declared type matches.
     *
     * @since   2.0.0
     */
    private function matchesAnyType(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            if (
                match ($type) {
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
                }
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Apply length and allowlisted format constraints to one string.
     *
     * @param   array<string, mixed>  $schema      Schema node being evaluated.
     * @param   string                $value       Runtime string value.
     * @param   string                $path        Path named in a violation.
     * @param   list<string>          $violations  Shared violation accumulator.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateString(array $schema, string $value, string $path, array &$violations): void
    {
        $length = mb_strlen($value);
        if (is_int($schema['minLength'] ?? null) && $length < $schema['minLength']) {
            $violations[] = $path . ' is shorter than minLength';
        }
        if (is_int($schema['maxLength'] ?? null) && $length > $schema['maxLength']) {
            $violations[] = $path . ' is longer than maxLength';
        }
        $format = $schema['format'] ?? null;
        if (is_string($format) && !$this->matchesFormat($format, $value)) {
            $violations[] = $path . ' has invalid ' . $format . ' format';
        }
    }

    /**
     * Apply list bounds and validate every member against the item schema.
     *
     * @param   array<string, mixed>  $schema      Array schema being evaluated.
     * @param   list<mixed>           $value       Runtime list value.
     * @param   string                $path        Path named in violations.
     * @param   list<string>          $violations  Shared violation accumulator.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateList(array $schema, array $value, string $path, array &$violations): void
    {
        if (is_int($schema['minItems'] ?? null) && count($value) < $schema['minItems']) {
            $violations[] = $path . ' has fewer than minItems';
        }
        if (is_int($schema['maxItems'] ?? null) && count($value) > $schema['maxItems']) {
            $violations[] = $path . ' has more than maxItems';
        }
        $items = $schema['items'] ?? null;
        if (is_array($items)) {
            /** @var array<string, mixed> $items */
            foreach ($value as $index => $item) {
                $this->validateValue($items, $item, $path . '[' . $index . ']', $violations);
            }
        }
    }

    /**
     * Apply object bounds, required fields, closed-property behavior, and child schemas.
     *
     * @param   array<string, mixed>  $schema      Object schema being evaluated.
     * @param   array<string, mixed>  $value       Runtime object value.
     * @param   string                $path        Path named in violations.
     * @param   list<string>          $violations  Shared violation accumulator.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateObject(array $schema, array $value, string $path, array &$violations): void
    {
        if (is_int($schema['minProperties'] ?? null) && count($value) < $schema['minProperties']) {
            $violations[] = $path . ' has fewer than minProperties';
        }
        if (is_int($schema['maxProperties'] ?? null) && count($value) > $schema['maxProperties']) {
            $violations[] = $path . ' has more than maxProperties';
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($required as $name) {
            if (is_string($name) && !array_key_exists($name, $value)) {
                $violations[] = $path . '.' . $name . ' is required';
            }
        }
        foreach ($value as $name => $item) {
            if (is_string($name) && isset($properties[$name]) && is_array($properties[$name])) {
                /** @var array<string, mixed> $child */
                $child = $properties[$name];
                $this->validateValue($child, $item, $path . '.' . $name, $violations);
            } else {
                $violations[] = $path . '.' . (string) $name . ' is not allowed';
            }
        }
    }

    /**
     * Check one allowlisted string format without network or filesystem access.
     *
     * @param   string  $format  Contract format name.
     * @param   string  $value   Runtime string to check.
     *
     * @return  bool  True when the value has the exact local format.
     *
     * @since   2.0.0
     */
    private function matchesFormat(string $format, string $value): bool
    {
        return match ($format) {
            'date' => self::matchesDate($value),
            'date-time' => DateTimeImmutable::createFromFormat(DATE_ATOM, $value) !== false,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uuid' => preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
                $value,
            ) === 1,
            default => false,
        };
    }

    /**
     * Require a calendar date to round-trip through the exact `Y-m-d` spelling.
     *
     * @param   string  $value  Candidate date string.
     *
     * @return  bool  True only for a real calendar date in canonical form.
     *
     * @since   2.0.0
     */
    private static function matchesDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
