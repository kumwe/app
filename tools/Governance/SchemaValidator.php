<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * Validates a decoded document against one of the governance JSON Schemas.
 *
 * The validator executes a fixed subset of JSON Schema 2020-12. A schema file that uses any other keyword is
 * refused when it is loaded, because a keyword the validator would silently ignore is a rule nobody enforces.
 * Violations are reported as `<json-pointer>: <rule>` so a failing record can be repaired line by line.
 *
 * @since  2.0.0
 */
final readonly class SchemaValidator
{
    /**
     * Keywords the validator executes; anything else in a schema file is a loading failure.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const KEYWORDS = [
        '$schema', '$id', 'title', 'description', 'type', 'properties', 'required', 'additionalProperties',
        'items', 'minItems', 'maxItems', 'uniqueItems', 'enum', 'const', 'pattern', 'minLength', 'maxLength',
        'minimum', 'maximum', 'oneOf', 'anyOf', 'allOf', 'not', '$ref', '$defs',
    ];

    /**
     * Type names `type` may use.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const TYPES = ['integer', 'number', 'string', 'boolean', 'null', 'array', 'object'];

    /**
     * Validate a decoded document against the schema stored at a path.
     *
     * @param   array<int|string, mixed>  $document    Decoded JSON or StrictYaml document.
     * @param   string                    $schemaPath  Absolute path of the schema file.
     *
     * @return  list<string>  Violations as `<json-pointer>: <rule>`; empty when the document is valid.
     *
     * @throws  GovernanceViolation  When the schema file is missing, malformed or uses an unsupported keyword.
     *
     * @since   2.0.0
     */
    public function validate(array $document, string $schemaPath): array
    {
        $schema = self::loadSchema($schemaPath);
        $violations = [];
        $this->check($document, $schema, $schema, '#', $violations);

        return $violations;
    }

    /**
     * Load a schema file and prove every keyword in it is one the validator executes.
     *
     * @param   string  $schemaPath  Absolute path of the schema file.
     *
     * @return  array<string, mixed>  The decoded schema.
     *
     * @throws  GovernanceViolation  When the file is missing, is not a JSON object or leaves the subset.
     *
     * @since   2.0.0
     */
    public static function loadSchema(string $schemaPath): array
    {
        $bytes = is_file($schemaPath) ? file_get_contents($schemaPath) : false;
        if (!is_string($bytes)) {
            throw GovernanceViolation::at($schemaPath, 'the schema file is missing', 'restore it from the repository');
        }
        /** @var mixed $decoded */
        $decoded = json_decode($bytes, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw GovernanceViolation::at($schemaPath, 'the schema file is not a JSON object', 'repair the JSON');
        }
        /** @var array<string, mixed> $decoded */
        self::assertSubset($decoded, $decoded, '#', $schemaPath);

        return $decoded;
    }

    /**
     * Walk a schema and refuse any keyword, reference or pattern the validator cannot execute.
     *
     * @param   array<string, mixed>  $schema   Subschema being inspected.
     * @param   array<string, mixed>  $root     The whole schema, for `$defs` lookups.
     * @param   string                $pointer  Location of the subschema inside the file.
     * @param   string                $path     Schema file path reported in violations.
     *
     * @return  void
     *
     * @throws  GovernanceViolation  When the subschema leaves the supported subset.
     *
     * @since   2.0.0
     */
    private static function assertSubset(array $schema, array $root, string $pointer, string $path): void
    {
        foreach ($schema as $keyword => $value) {
            if (!in_array($keyword, self::KEYWORDS, true)) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('%s uses the keyword "%s", which the validator does not execute', $pointer, $keyword),
                    'express the rule with the supported 2020-12 subset or extend SchemaValidator',
                );
            }
        }

        $nested = [];
        foreach (['properties', '$defs'] as $container) {
            if (isset($schema[$container])) {
                if (!is_array($schema[$container]) || array_is_list($schema[$container])) {
                    throw GovernanceViolation::at(
                        $path,
                        sprintf('%s/%s must be an object', $pointer, $container),
                        'repair it',
                    );
                }
                foreach ($schema[$container] as $name => $child) {
                    $nested[$pointer . '/' . $container . '/' . self::escape((string) $name)] = $child;
                }
            }
        }
        foreach (['items', 'not', 'additionalProperties'] as $single) {
            if (isset($schema[$single]) && is_array($schema[$single])) {
                $nested[$pointer . '/' . $single] = $schema[$single];
            }
        }
        foreach (['oneOf', 'anyOf', 'allOf'] as $list) {
            if (isset($schema[$list])) {
                if (!is_array($schema[$list]) || !array_is_list($schema[$list]) || $schema[$list] === []) {
                    throw GovernanceViolation::at(
                        $path,
                        sprintf('%s/%s must be a non-empty list', $pointer, $list),
                        'repair it',
                    );
                }
                foreach ($schema[$list] as $offset => $child) {
                    $nested[$pointer . '/' . $list . '/' . $offset] = $child;
                }
            }
        }
        foreach ($nested as $childPointer => $child) {
            if (!is_array($child) || ($child !== [] && array_is_list($child))) {
                throw GovernanceViolation::at($path, sprintf('%s must be a schema object', $childPointer), 'repair it');
            }
            /** @var array<string, mixed> $child */
            self::assertSubset($child, $root, $childPointer, $path);
        }

        if (isset($schema['$ref'])) {
            $reference = $schema['$ref'];
            if (!is_string($reference) || preg_match('/^#\/\$defs\/([A-Za-z0-9_-]+)$/', $reference, $match) !== 1) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('%s/$ref must point to "#/$defs/<name>"', $pointer),
                    'define the shape under $defs and reference it there',
                );
            }
            if (!isset($root['$defs']) || !is_array($root['$defs']) || !isset($root['$defs'][$match[1]])) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('%s/$ref names an undefined $defs entry', $pointer),
                    'define it',
                );
            }
        }
        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            foreach ($types as $type) {
                if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                    throw GovernanceViolation::at(
                        $path,
                        sprintf('%s/type names an unknown type', $pointer),
                        'repair it',
                    );
                }
            }
        }
        if (isset($schema['pattern'])) {
            if (!is_string($schema['pattern']) || @preg_match(self::regex($schema['pattern']), '') === false) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('%s/pattern is not a valid expression', $pointer),
                    'repair it',
                );
            }
        }
        foreach (['enum', 'required'] as $list) {
            if (isset($schema[$list]) && (!is_array($schema[$list]) || !array_is_list($schema[$list]))) {
                throw GovernanceViolation::at($path, sprintf('%s/%s must be a list', $pointer, $list), 'repair it');
            }
        }
    }

    /**
     * Validate one value against one subschema, appending every violation found.
     *
     * @param   mixed                 $value       The value at the pointer.
     * @param   array<string, mixed>  $schema      Subschema to apply.
     * @param   array<string, mixed>  $root        The whole schema, for `$ref`.
     * @param   string                $pointer     JSON pointer of the value, `#` for the root.
     * @param   list<string>          $violations  Accumulated violations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function check(mixed $value, array $schema, array $root, string $pointer, array &$violations): void
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            /** @var array<string, array<string, mixed>> $definitions */
            $definitions = $root['$defs'];
            $this->check(
                $value,
                $definitions[substr($schema['$ref'], strlen('#/$defs/'))],
                $root,
                $pointer,
                $violations,
            );
        }

        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? array_values($schema['type']) : [$schema['type']];
            /** @var list<string> $types */
            $matched = false;
            foreach ($types as $type) {
                if (self::isType($value, $type)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $violations[] = sprintf(
                    '%s: must be of type %s, %s given',
                    $pointer,
                    implode(' or ', $types),
                    self::typeOf($value),
                );

                return;
            }
        }

        if (array_key_exists('const', $schema) && !self::same($value, $schema['const'])) {
            $violations[] = sprintf('%s: must equal %s', $pointer, self::render($schema['const']));
        }
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $allowed = false;
            foreach ($schema['enum'] as $candidate) {
                if (self::same($value, $candidate)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $violations[] = sprintf(
                    '%s: must be one of %s',
                    $pointer,
                    implode(', ', array_map(self::render(...), $schema['enum'])),
                );
            }
        }

        if (is_string($value)) {
            $this->checkString($value, $schema, $pointer, $violations);
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && is_numeric($schema['minimum']) && $value < $schema['minimum']) {
                $violations[] = sprintf('%s: must be at least %s', $pointer, self::render($schema['minimum']));
            }
            if (isset($schema['maximum']) && is_numeric($schema['maximum']) && $value > $schema['maximum']) {
                $violations[] = sprintf('%s: must be at most %s', $pointer, self::render($schema['maximum']));
            }
        }
        if (is_array($value) && array_is_list($value)) {
            $this->checkList($value, $schema, $root, $pointer, $violations);
        }
        if (is_array($value) && ($value === [] || !array_is_list($value))) {
            $this->checkObject($value, $schema, $root, $pointer, $violations);
        }

        $this->checkCombinators($value, $schema, $root, $pointer, $violations);
    }

    /**
     * Apply the string keywords.
     *
     * @param   string                $value       The string.
     * @param   array<string, mixed>  $schema      Subschema to apply.
     * @param   string                $pointer     JSON pointer of the value.
     * @param   list<string>          $violations  Accumulated violations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function checkString(string $value, array $schema, string $pointer, array &$violations): void
    {
        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            if (preg_match(self::regex($schema['pattern']), $value) !== 1) {
                $violations[] = sprintf('%s: must match pattern %s', $pointer, $schema['pattern']);
            }
        }
        $length = mb_strlen($value);
        if (isset($schema['minLength']) && is_int($schema['minLength']) && $length < $schema['minLength']) {
            $violations[] = sprintf('%s: must be at least %d characters long', $pointer, $schema['minLength']);
        }
        if (isset($schema['maxLength']) && is_int($schema['maxLength']) && $length > $schema['maxLength']) {
            $violations[] = sprintf('%s: must be at most %d characters long', $pointer, $schema['maxLength']);
        }
    }

    /**
     * Apply the array keywords.
     *
     * @param   list<mixed>           $value       The list.
     * @param   array<string, mixed>  $schema      Subschema to apply.
     * @param   array<string, mixed>  $root        The whole schema.
     * @param   string                $pointer     JSON pointer of the value.
     * @param   list<string>          $violations  Accumulated violations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function checkList(array $value, array $schema, array $root, string $pointer, array &$violations): void
    {
        $count = count($value);
        if (isset($schema['minItems']) && is_int($schema['minItems']) && $count < $schema['minItems']) {
            $violations[] = sprintf('%s: must have at least %d items', $pointer, $schema['minItems']);
        }
        if (isset($schema['maxItems']) && is_int($schema['maxItems']) && $count > $schema['maxItems']) {
            $violations[] = sprintf('%s: must have at most %d items', $pointer, $schema['maxItems']);
        }
        if (($schema['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($value as $item) {
                $key = json_encode($item, JSON_THROW_ON_ERROR);
                if (isset($seen[$key])) {
                    $violations[] = sprintf('%s: must not contain duplicate items', $pointer);
                    break;
                }
                $seen[$key] = true;
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            /** @var array<string, mixed> $items */
            $items = $schema['items'];
            foreach ($value as $offset => $item) {
                $this->check($item, $items, $root, $pointer . '/' . $offset, $violations);
            }
        }
    }

    /**
     * Apply the object keywords.
     *
     * @param   array<int|string, mixed>  $value       The object; an empty array counts as an empty object.
     * @param   array<string, mixed>      $schema      Subschema to apply.
     * @param   array<string, mixed>      $root        The whole schema.
     * @param   string                    $pointer     JSON pointer of the value.
     * @param   list<string>              $violations  Accumulated violations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function checkObject(array $value, array $schema, array $root, string $pointer, array &$violations): void
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $name) {
                if (is_string($name) && !array_key_exists($name, $value)) {
                    $violations[] = sprintf('%s: is missing the required property "%s"', $pointer, $name);
                }
            }
        }
        foreach ($value as $name => $member) {
            $childPointer = $pointer . '/' . self::escape((string) $name);
            if (isset($properties[$name])) {
                $this->check($member, $properties[$name], $root, $childPointer, $violations);
                continue;
            }
            $additional = $schema['additionalProperties'] ?? true;
            if ($additional === false) {
                $violations[] = sprintf('%s: the property "%s" is not allowed here', $childPointer, (string) $name);
                continue;
            }
            if (is_array($additional)) {
                /** @var array<string, mixed> $additional */
                $this->check($member, $additional, $root, $childPointer, $violations);
            }
        }
    }

    /**
     * Apply `oneOf`, `anyOf`, `allOf` and `not`.
     *
     * @param   mixed                 $value       The value.
     * @param   array<string, mixed>  $schema      Subschema to apply.
     * @param   array<string, mixed>  $root        The whole schema.
     * @param   string                $pointer     JSON pointer of the value.
     * @param   list<string>          $violations  Accumulated violations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function checkCombinators(
        mixed $value,
        array $schema,
        array $root,
        string $pointer,
        array &$violations,
    ): void
    {
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $alternative) {
                /** @var array<string, mixed> $alternative */
                $this->check($value, $alternative, $root, $pointer, $violations);
            }
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }
            $matches = 0;
            $closest = null;
            foreach ($schema[$keyword] as $alternative) {
                /** @var array<string, mixed> $alternative */
                $scratch = [];
                $this->check($value, $alternative, $root, $pointer, $scratch);
                if ($scratch === []) {
                    $matches++;
                    continue;
                }
                if ($closest === null || count($scratch) < count($closest)) {
                    $closest = $scratch;
                }
            }
            if ($keyword === 'anyOf' && $matches === 0) {
                $violations[] = sprintf('%s: must match at least one alternative', $pointer);
                array_push($violations, ...($closest ?? []));
            }
            if ($keyword === 'oneOf' && $matches !== 1) {
                $violations[] = sprintf('%s: must match exactly one alternative (%d matched)', $pointer, $matches);
                if ($matches === 0) {
                    array_push($violations, ...($closest ?? []));
                }
            }
        }
        if (isset($schema['not']) && is_array($schema['not'])) {
            /** @var array<string, mixed> $excluded */
            $excluded = $schema['not'];
            $scratch = [];
            $this->check($value, $excluded, $root, $pointer, $scratch);
            if ($scratch === []) {
                $violations[] = sprintf('%s: must not match the excluded schema', $pointer);
            }
        }
    }

    /**
     * Decide whether a value has a JSON Schema type.
     *
     * A decoded empty array is indistinguishable from an empty object, so it satisfies both `array` and
     * `object`; the surrounding `required` and `minItems` rules decide what an empty value may mean.
     *
     * @param   mixed   $value  The value.
     * @param   string  $type   One of the seven type names.
     *
     * @return  bool  True when the value is of that type.
     *
     * @since   2.0.0
     */
    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            default => false,
        };
    }

    /**
     * Name the JSON type of a value for a violation message.
     *
     * @param   mixed  $value  The value.
     *
     * @return  string  One of the seven type names.
     *
     * @since   2.0.0
     */
    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && array_is_list($value) && $value !== [] => 'array',
            default => 'object',
        };
    }

    /**
     * Compare two decoded values for JSON equality.
     *
     * @param   mixed  $left   First value.
     * @param   mixed  $right  Second value.
     *
     * @return  bool  True when both encode to the same JSON.
     *
     * @since   2.0.0
     */
    private static function same(mixed $left, mixed $right): bool
    {
        return json_encode($left, JSON_THROW_ON_ERROR) === json_encode($right, JSON_THROW_ON_ERROR);
    }

    /**
     * Render a schema value for a violation message.
     *
     * @param   mixed  $value  The value.
     *
     * @return  string  Its JSON encoding.
     *
     * @since   2.0.0
     */
    private static function render(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Wrap a schema pattern as a PCRE expression.
     *
     * @param   string  $pattern  ECMA-style pattern from the schema.
     *
     * @return  string  Delimited, Unicode-aware expression.
     *
     * @since   2.0.0
     */
    private static function regex(string $pattern): string
    {
        return '~' . str_replace('~', '\\~', $pattern) . '~u';
    }

    /**
     * Escape a JSON pointer segment.
     *
     * @param   string  $segment  Property name or index.
     *
     * @return  string  The segment with `~` and `/` escaped per RFC 6901.
     *
     * @since   2.0.0
     */
    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
