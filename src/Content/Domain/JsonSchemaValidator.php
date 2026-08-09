<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

use InvalidArgumentException;

/**
 * Deterministic, side-effect-free JSON Schema subset used by persisted content contracts.
 *
 * Content types are authored by operators, so a schema is untrusted input twice over: once as a
 * document that has to be safe to store, and once as a program that has to be safe to run on every
 * save. Restricting the language to a fixed keyword list is what buys that — no remote `$ref` to
 * fetch, no recursion into keywords with surprising semantics, no engine-dependent behaviour — and
 * `assertSupported()` enforces the restriction before a definition is ever persisted, so evaluating a
 * stored schema is always cheap and always terminates. Both entry points report every problem they
 * find rather than the first, because the caller is usually rendering a form. The validator holds no
 * state and touches nothing outside its arguments, so a single instance is safely shared.
 *
 * @since  2.0.0
 */
final class JsonSchemaValidator
{
    /**
     * Every schema keyword the subset understands; anything else is reported as unsupported.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const KEYWORDS = [
        'type', 'title', 'description', 'default', 'properties', 'required', 'additionalProperties',
        'items', 'enum', 'const', 'minLength', 'maxLength', 'minimum', 'maximum', 'minItems', 'maxItems',
        'pattern', 'format', 'anyOf', 'oneOf', 'allOf', 'x-kumwe-field',
    ];

    /**
     * Refuse a schema that reaches outside the enforceable subset.
     *
     * Run this before a content type is stored: a schema that survives it can be evaluated later
     * without further checks. Unsupported keywords, unknown types and formats, and bounds that
     * contradict each other are all collected into one message rather than reported one save at a time.
     *
     * @param   array<string, mixed>  $schema  Candidate content type schema, as the operator authored it.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the schema uses anything the validator cannot enforce.
     *
     * @since   2.0.0
     */
    public function assertSupported(array $schema): void
    {
        $violations = [];
        $this->validateSchema($schema, '$', $violations);
        if ($violations !== []) {
            /** @var non-empty-list<string> $violations */
            throw new InvalidArgumentException('Unsupported content schema: ' . implode('; ', $violations));
        }
    }

    /**
     * Check a value against a schema, after re-confirming that the schema itself is enforceable.
     *
     * The schema is re-checked on every call rather than trusted from storage, so a definition that
     * was edited around the domain cannot smuggle an unsupported keyword into evaluation. Value
     * violations are gathered in full and raised together, letting an editor see every failing field
     * from a single save.
     *
     * @param   array<string, mixed>  $schema  Schema the value must satisfy.
     * @param   mixed                 $value   Decoded content to check, normally an entry's data map.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the schema itself falls outside the supported subset.
     * @throws  InvalidContentData  When the value breaks the schema; carries every violation found.
     *
     * @since   2.0.0
     */
    public function assertValid(array $schema, mixed $value): void
    {
        $this->assertSupported($schema);
        $violations = [];
        $this->validateValue($schema, $value, '$', $violations);
        if ($violations !== []) {
            /** @var non-empty-list<string> $violations */
            throw new InvalidContentData($violations);
        }
    }

    /**
     * Inspect one schema node and append a message for every keyword it uses wrongly.
     *
     * Recurses through `properties`, `items` and the combinators, so one call covers the whole
     * document. It never returns early after the first problem, except when the node is not an object
     * at all and nothing further can be said about it.
     *
     * @param   array<string, mixed>  $schema      Schema node being inspected.
     * @param   string                $path        JSON path of this node, used to prefix the messages it produces.
     * @param   list<string>          $violations  Accumulator owned by the caller; appended to in place.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateSchema(array $schema, string $path, array &$violations): void
    {
        if ($schema !== [] && array_is_list($schema)) {
            $violations[] = $path . ' must be a schema object';
            return;
        }
        foreach (array_keys($schema) as $keyword) {
            if (!in_array($keyword, self::KEYWORDS, true)) {
                $violations[] = $path . ' contains unsupported keyword ' . $keyword;
            }
        }
        $type = $schema['type'] ?? null;
        $types = ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'];
        if ($type !== null && (!is_string($type) || !in_array($type, $types, true))) {
            $violations[] = $path . '.type is unsupported';
        }
        foreach (['title', 'description'] as $keyword) {
            if (isset($schema[$keyword]) && !is_string($schema[$keyword])) {
                $violations[] = $path . '.' . $keyword . ' must be a string';
            }
        }
        $required = $schema['required'] ?? null;
        if ($required !== null) {
            if (!is_array($required) || !array_is_list($required)) {
                $violations[] = $path . '.required must be a string list';
            } else {
                $seenRequired = [];
                foreach ($required as $field) {
                    if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field) !== 1) {
                        $violations[] = $path . '.required contains an invalid field key';
                    } elseif (isset($seenRequired[$field])) {
                        $violations[] = $path . '.required contains duplicate field ' . $field;
                    }
                    if (is_string($field)) {
                        $seenRequired[$field] = true;
                    }
                }
            }
        }
        if (isset($schema['additionalProperties']) && !is_bool($schema['additionalProperties'])) {
            $violations[] = $path . '.additionalProperties must be a boolean';
        }
        if (
            isset($schema['enum'])
            && (!is_array($schema['enum']) || !array_is_list($schema['enum']) || $schema['enum'] === [])
        ) {
            $violations[] = $path . '.enum must be a non-empty list';
        }
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $keyword) {
            if (isset($schema[$keyword]) && (!is_int($schema[$keyword]) || $schema[$keyword] < 0)) {
                $violations[] = $path . '.' . $keyword . ' must be a non-negative integer';
            }
        }
        foreach ([['minLength', 'maxLength'], ['minItems', 'maxItems']] as [$minimum, $maximum]) {
            if (
                is_int($schema[$minimum] ?? null)
                && is_int($schema[$maximum] ?? null)
                && $schema[$minimum] > $schema[$maximum]
            ) {
                $violations[] = $path . '.' . $minimum . ' cannot exceed ' . $maximum;
            }
        }
        foreach (['minimum', 'maximum'] as $keyword) {
            $bound = $schema[$keyword] ?? null;
            if ($bound !== null && (!is_int($bound) && (!is_float($bound) || !is_finite($bound)))) {
                $violations[] = $path . '.' . $keyword . ' must be a finite number';
            }
        }
        $minimumBound = $schema['minimum'] ?? null;
        $maximumBound = $schema['maximum'] ?? null;
        if (
            (is_int($minimumBound) || is_float($minimumBound))
            && (is_int($maximumBound) || is_float($maximumBound))
            && $minimumBound > $maximumBound
        ) {
            $violations[] = $path . '.minimum cannot exceed maximum';
        }
        $properties = $schema['properties'] ?? null;
        if ($properties !== null) {
            if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
                $violations[] = $path . '.properties must be an object';
            } else {
                foreach ($properties as $key => $child) {
                    if (
                        !is_string($key)
                        || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1
                        || !is_array($child)
                    ) {
                        $violations[] = $path . '.properties contains an invalid field';
                    } else {
                        /** @var array<string, mixed> $child */
                        $this->validateSchema($child, $path . '.properties.' . $key, $violations);
                    }
                }
                if (is_array($required) && array_is_list($required)) {
                    foreach ($required as $field) {
                        if (is_string($field) && !array_key_exists($field, $properties)) {
                            $violations[] = $path . '.required references undefined field ' . $field;
                        }
                    }
                }
            }
        }
        foreach (['items'] as $keyword) {
            if (isset($schema[$keyword])) {
                if (!is_array($schema[$keyword])) {
                    $violations[] = $path . '.' . $keyword . ' must be a schema';
                } else {
                    /** @var array<string, mixed> $itemSchema */
                    $itemSchema = $schema[$keyword];
                    $this->validateSchema($itemSchema, $path . '.' . $keyword, $violations);
                }
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (!isset($schema[$keyword])) {
                continue;
            }
            if (!is_array($schema[$keyword]) || !array_is_list($schema[$keyword]) || $schema[$keyword] === []) {
                $violations[] = $path . '.' . $keyword . ' must be a non-empty schema list';
                continue;
            }
            foreach ($schema[$keyword] as $index => $child) {
                if (!is_array($child)) {
                    $violations[] = $path . '.' . $keyword . '[' . $index . '] must be a schema';
                } else {
                    /** @var array<string, mixed> $child */
                    $this->validateSchema($child, $path . '.' . $keyword . '[' . $index . ']', $violations);
                }
            }
        }
        $pattern = $schema['pattern'] ?? null;
        if (
            $pattern !== null && (!is_string($pattern)
            || @preg_match('/' . str_replace('/', '\\/', $pattern) . '/u', '') === false)
        ) {
            $violations[] = $path . '.pattern must be a valid regular expression';
        }
        $formats = ['date-time', 'date', 'email', 'uri', 'uri-reference', 'uuid'];
        if (isset($schema['format']) && !in_array($schema['format'], $formats, true)) {
            $violations[] = $path . '.format is unsupported';
        }
        if (isset($schema['x-kumwe-field']) && $schema['x-kumwe-field'] !== 'media') {
            $violations[] = $path . '.x-kumwe-field is unsupported';
        }
    }

    /**
     * Check one value against one schema node, appending a message per failing constraint.
     *
     * A type mismatch stops the node, since the remaining constraints would only restate it; every
     * other failure is recorded and checking continues into list items and object properties.
     * Combinator branches are evaluated against a throwaway accumulator, so a failing branch
     * contributes only the combinator's own message and not each of its internal complaints.
     *
     * @param   array<string, mixed>  $schema      Schema node the value must satisfy.
     * @param   mixed                 $value       Value found at this path.
     * @param   string                $path        JSON path of the value, used to prefix the messages it produces.
     * @param   list<string>          $violations  Accumulator owned by the caller; appended to in place.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateValue(array $schema, mixed $value, string $path, array &$violations): void
    {
        $type = $schema['type'] ?? null;
        if (is_string($type) && !$this->matchesType($type, $value)) {
            $violations[] = $path . ' must be ' . $type;
            return;
        }
        if (isset($schema['const']) && $value !== $schema['const']) {
            $violations[] = $path . ' must equal its const value';
        }
        if (isset($schema['enum']) && (!is_array($schema['enum']) || !in_array($value, $schema['enum'], true))) {
            $violations[] = $path . ' is not an allowed enum value';
        }
        if (is_string($value)) {
            $length = mb_strlen($value);
            $minLength = $schema['minLength'] ?? null;
            if (is_int($minLength) && $length < $minLength) {
                $violations[] = $path . ' is shorter than minLength';
            }
            $maxLength = $schema['maxLength'] ?? null;
            if (is_int($maxLength) && $length > $maxLength) {
                $violations[] = $path . ' is longer than maxLength';
            }
            if (
                isset($schema['pattern'])
                && is_string($schema['pattern'])
                && preg_match('/' . str_replace('/', '\\/', $schema['pattern']) . '/u', $value) !== 1
            ) {
                $violations[] = $path . ' does not match pattern';
            }
            if (
                isset($schema['format'])
                && is_string($schema['format'])
                && !$this->matchesFormat($schema['format'], $value)
            ) {
                $violations[] = $path . ' is not a valid ' . $schema['format'];
            }
        }
        if (is_int($value) || is_float($value)) {
            $minimum = $schema['minimum'] ?? null;
            if ((is_int($minimum) || is_float($minimum)) && $value < $minimum) {
                $violations[] = $path . ' is below minimum';
            }
            $maximum = $schema['maximum'] ?? null;
            if ((is_int($maximum) || is_float($maximum)) && $value > $maximum) {
                $violations[] = $path . ' is above maximum';
            }
        }
        if (is_array($value) && array_is_list($value)) {
            $minItems = $schema['minItems'] ?? null;
            if (is_int($minItems) && count($value) < $minItems) {
                $violations[] = $path . ' has fewer than minItems';
            }
            $maxItems = $schema['maxItems'] ?? null;
            if (is_int($maxItems) && count($value) > $maxItems) {
                $violations[] = $path . ' has more than maxItems';
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                /** @var array<string, mixed> $itemSchema */
                $itemSchema = $schema['items'];
                foreach ($value as $index => $item) {
                    $this->validateValue($itemSchema, $item, $path . '[' . $index . ']', $violations);
                }
            }
        }
        if (is_array($value) && ($value === [] || !array_is_list($value))) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            foreach ($required as $key) {
                if (is_string($key) && !array_key_exists($key, $value)) {
                    $violations[] = $path . '.' . $key . ' is required';
                }
            }
            foreach ($value as $key => $item) {
                if (is_string($key) && isset($properties[$key]) && is_array($properties[$key])) {
                    /** @var array<string, mixed> $propertySchema */
                    $propertySchema = $properties[$key];
                    $this->validateValue($propertySchema, $item, $path . '.' . $key, $violations);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $violations[] = $path . '.' . (string) $key . ' is not allowed';
                }
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }
            $matches = 0;
            foreach ($schema[$keyword] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                /** @var array<string, mixed> $child */
                $childViolations = [];
                $this->validateValue($child, $value, $path, $childViolations);
                $matches += $childViolations === [] ? 1 : 0;
            }
            $matchesAll = $keyword === 'allOf' && $matches === count($schema[$keyword]);
            $matchesAny = $keyword === 'anyOf' && $matches >= 1;
            $matchesOne = $keyword === 'oneOf' && $matches === 1;
            if (!$matchesAll && !$matchesAny && !$matchesOne) {
                $violations[] = $path . ' does not satisfy ' . $keyword;
            }
        }
    }

    /**
     * Decide whether a PHP value counts as the named JSON Schema type.
     *
     * An empty array satisfies both `object` and `array`, because PHP cannot distinguish an empty map
     * from an empty list; `number` excludes infinities and NAN, which have no JSON form.
     *
     * @param   string  $type   Supported type name taken from the schema's `type` keyword.
     * @param   mixed   $value  Value being classified.
     *
     * @return  bool  True when the value is of that type, false for any name outside the subset.
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
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    /**
     * Decide whether a string satisfies one of the supported `format` values.
     *
     * Dates are held to their exact spelling — `date-time` to RFC 3339 and `date` to `Y-m-d` — rather
     * than accepting whatever the date parser can salvage, so a stored value round-trips unchanged.
     *
     * @param   string  $format  Format name taken from the schema's `format` keyword.
     * @param   string  $value   String being checked.
     *
     * @return  bool  True when the string satisfies the format, false for any unrecognised name.
     *
     * @since   2.0.0
     */
    private function matchesFormat(string $format, string $value): bool
    {
        return match ($format) {
            'date-time' => \DateTimeImmutable::createFromFormat(DATE_ATOM, $value) !== false,
            'date' => \DateTimeImmutable::createFromFormat('!Y-m-d', $value) !== false,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'uri-reference' => $this->isUriReference($value),
            'uuid' => preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
                $value,
            ) === 1,
            default => false,
        };
    }

    /**
     * Decide whether a string is a URI reference that is safe to store and later emit as a link.
     *
     * Accepts an absolute URL, a bare fragment, or a rooted path. Protocol-relative forms, control
     * characters, backslashes, doubled slashes and any `.` or `..` segment — including percent-encoded
     * ones, which is why the path is decoded first — are rejected, so a stored reference cannot be
     * resolved into a host or a location the author did not write.
     *
     * @param   string  $value  Candidate reference.
     *
     * @return  bool  True for an absolute URL, a fragment, or a safe rooted path.
     *
     * @since   2.0.0
     */
    private function isUriReference(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }
        if (preg_match('/^#[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $value) === 1) {
            return true;
        }
        if (
            $value === ''
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
            || str_contains($value, '\\')
            || !str_starts_with($value, '/')
            || str_starts_with($value, '//')
        ) {
            return false;
        }
        $path = parse_url($value, PHP_URL_PATH);
        $decoded = is_string($path) ? rawurldecode($path) : null;

        return is_string($decoded)
            && !str_contains($decoded, '\\')
            && !str_contains($decoded, '//')
            && preg_match('#(?:^|/)\.{1,2}(?:/|$)#D', $decoded) !== 1;
    }
}
