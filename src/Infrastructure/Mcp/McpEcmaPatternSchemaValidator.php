<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use Mcp\Capability\Discovery\SchemaValidator;

/**
 * Validates tool arguments as JSON Schema defines them where the SDK's PHP decoding loses the distinction.
 *
 * JSON Schema patterns are ECMA-262 regular expressions, and the catalogue's control-character guards are
 * written that way (`[^\u0000-\u001F\u007F]`), which is how every client reads the advertised schema. The
 * SDK's validator compiles patterns with PCRE, which has no `\u` escape, so it refused the whole call before
 * the handler ran. This subclass rewrites each such escape to PCRE's `\x{XXXX}` for validation only. The SDK
 * also decodes arguments to PHP arrays, so an empty JSON object such as `"input": {}` reached the validator
 * as a list and failed `"type": "object"`; an empty array is read back as an object wherever the schema
 * admits an object and no array. The advertised schemas, the retained contract generations that pin them,
 * and the arguments handed to the handler are untouched. It extends the SDK class because `CallToolHandler`
 * accepts only that type, which is also why it cannot be `readonly`.
 *
 * @since  2.0.0
 */
final class McpEcmaPatternSchemaValidator extends SchemaValidator
{
    /**
     * Keywords whose values are instance data rather than subschemas, left exactly as declared.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array DATA_KEYWORDS = ['const', 'default', 'enum', 'examples'];

    /**
     * Validate one argument document against a tool schema whose patterns were translated for PCRE.
     *
     * @param   mixed                        $data    Decoded arguments.
     * @param   array<string, mixed>|object  $schema  Tool input schema as registered.
     *
     * @return  list<array{pointer: string, keyword: string, message: string}>  Validation errors, empty when valid.
     *
     * @since   2.0.0
     */
    public function validateAgainstJsonSchema(mixed $data, array|object $schema): array
    {
        $schema = self::translate($schema);
        // A JSON Schema document is a JSON object; the parent re-encodes it either way.
        $schema = is_array($schema) ? (object) $schema : $schema;

        return parent::validateAgainstJsonSchema(self::objects($data, $schema), $schema);
    }

    /**
     * Rewrite every unescaped ECMA-262 `\uXXXX` escape in one pattern to PCRE's `\x{XXXX}`.
     *
     * Escapes are consumed pairwise from the left, so an escaped backslash followed by `u` stays literal.
     *
     * @param   string  $pattern  ECMA-262 pattern.
     *
     * @return  string  Equivalent PCRE pattern.
     *
     * @since   2.0.0
     */
    public static function pcrePattern(string $pattern): string
    {
        return (string) preg_replace_callback(
            '/\\\\(?:u([0-9A-Fa-f]{4})|.)/s',
            static fn (array $match): string => isset($match[1]) ? '\\x{' . $match[1] . '}' : $match[0],
            $pattern,
        );
    }

    /**
     * Read back empty JSON objects the SDK decoded as empty PHP arrays, guided by the schema.
     *
     * @param   mixed  $data    Decoded argument value.
     * @param   mixed  $schema  Schema the value is validated against.
     *
     * @return  mixed  Value with every empty array an object-only schema expects replaced by an object.
     *
     * @since   2.0.0
     */
    private static function objects(mixed $data, mixed $schema): mixed
    {
        if (!is_array($data) || (!is_array($schema) && !is_object($schema))) {
            return $data;
        }
        $members = is_object($schema) ? get_object_vars($schema) : $schema;
        if ($data === []) {
            return self::objectOnly($members) ? new \stdClass() : $data;
        }
        $properties = $members['properties'] ?? null;
        $properties = is_object($properties) ? get_object_vars($properties) : $properties;
        $items = $members['items'] ?? null;
        foreach ($data as $key => $value) {
            if (array_is_list($data)) {
                $data[$key] = self::objects($value, $items);
            } elseif (is_array($properties) && array_key_exists($key, $properties)) {
                $data[$key] = self::objects($value, $properties[$key]);
            }
        }

        return $data;
    }

    /**
     * Whether a schema admits a JSON object but not an array, directly or through one `anyOf`/`oneOf` branch.
     *
     * @param   array<array-key, mixed>  $members  Schema members.
     *
     * @return  bool  Whether an empty value must be an object.
     *
     * @since   2.0.0
     */
    private static function objectOnly(array $members): bool
    {
        $types = $members['type'] ?? null;
        $types = is_string($types) ? [$types] : (is_array($types) ? $types : []);
        if (in_array('array', $types, true)) {
            return false;
        }
        if (in_array('object', $types, true)) {
            return true;
        }
        $admitsObject = false;
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branches = $members[$keyword] ?? [];
            foreach (is_array($branches) ? $branches : [] as $branch) {
                $branch = is_object($branch) ? get_object_vars($branch) : $branch;
                if (!is_array($branch)) {
                    continue;
                }
                $branchTypes = $branch['type'] ?? null;
                $branchTypes = is_string($branchTypes) ? [$branchTypes] : (is_array($branchTypes) ? $branchTypes : []);
                if (in_array('array', $branchTypes, true)) {
                    return false;
                }
                $admitsObject = $admitsObject || in_array('object', $branchTypes, true);
            }
        }

        return $admitsObject;
    }

    /**
     * Translate the patterns of one schema node and every subschema below it.
     *
     * @param   array<array-key, mixed>|object  $node  Schema node as an array or decoded object.
     *
     * @return  array<array-key, mixed>|object  Node of the same shape with PCRE patterns.
     *
     * @since   2.0.0
     */
    private static function translate(array|object $node): array|object
    {
        $members = is_object($node) ? get_object_vars($node) : $node;
        foreach ($members as $key => $value) {
            if (in_array($key, self::DATA_KEYWORDS, true)) {
                continue;
            }
            if ($key === 'pattern' && is_string($value)) {
                $members[$key] = self::pcrePattern($value);
            } elseif ($key === 'patternProperties' && (is_array($value) || is_object($value))) {
                $translated = [];
                foreach (is_object($value) ? get_object_vars($value) : $value as $name => $member) {
                    $translated[self::pcrePattern((string) $name)] = is_array($member) || is_object($member)
                        ? self::translate($member)
                        : $member;
                }
                $members[$key] = is_object($value) ? (object) $translated : $translated;
            } elseif (is_array($value) || is_object($value)) {
                $members[$key] = self::translate($value);
            }
        }

        return is_object($node) ? (object) $members : $members;
    }
}
