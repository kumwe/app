<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use LogicException;
use stdClass;

/**
 * Preserves JSON Schema object semantics across PHP arrays and the SDK v0.7.1 builder path.
 *
 * PHP uses the same empty array for JSON `[]` and `{}`. Catalogue property maps deliberately remain PHP
 * arrays so their keys can be validated, but an empty schema object must become `stdClass` before it reaches
 * the wire or Opis validator. This normalizer understands schema-bearing keywords instead of converting all
 * empty arrays, preserving real lists such as `required`, `enum`, `allOf` and `prefixItems`.
 *
 * @since  2.0.0
 */
final readonly class McpJsonSchemaNormalizer
{
    /**
     * Keywords whose value is one JSON Schema, or a boolean schema.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array SCHEMA_MEMBERS = [
        'additionalProperties',
        'contains',
        'else',
        'if',
        'items',
        'not',
        'propertyNames',
        'then',
        'unevaluatedItems',
        'unevaluatedProperties',
    ];

    /**
     * Keywords whose value is an object mapping names or patterns to schemas.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array SCHEMA_MAP_MEMBERS = [
        '$defs',
        'definitions',
        'dependentSchemas',
        'patternProperties',
        'properties',
    ];

    /**
     * Keywords whose value remains a JSON list containing schemas.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array SCHEMA_LIST_MEMBERS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /**
     * Normalize every schema position while leaving vocabulary lists and scalar constraints untouched.
     *
     * @param   array<string, mixed>  $schema  Catalogue JSON Schema object.
     *
     * @return  array<string, mixed>  Wire-valid schema with empty schema objects represented as `{}`.
     *
     * @since   2.0.0
     */
    public static function normalize(array $schema): array
    {
        foreach (self::SCHEMA_MAP_MEMBERS as $keyword) {
            if (!array_key_exists($keyword, $schema) || !is_array($schema[$keyword])) {
                continue;
            }
            if ($schema[$keyword] === []) {
                $schema[$keyword] = new stdClass();

                continue;
            }
            foreach ($schema[$keyword] as $name => $member) {
                if ($member === []) {
                    $schema[$keyword][$name] = new stdClass();
                } elseif (is_array($member)) {
                    $schema[$keyword][$name] = self::normalize(self::stringKeyed($member));
                }
            }
        }

        foreach (self::SCHEMA_MEMBERS as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            if ($schema[$keyword] === []) {
                $schema[$keyword] = new stdClass();
            } elseif (is_array($schema[$keyword])) {
                $schema[$keyword] = self::normalize(self::stringKeyed($schema[$keyword]));
            }
        }

        foreach (self::SCHEMA_LIST_MEMBERS as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }
            foreach ($schema[$keyword] as $offset => $member) {
                if ($member === []) {
                    $schema[$keyword][$offset] = new stdClass();
                } elseif (is_array($member)) {
                    $schema[$keyword][$offset] = self::normalize(self::stringKeyed($member));
                }
            }
        }

        return $schema;
    }

    /**
     * Prove that one schema object uses JSON-compatible string member names.
     *
     * @param   array<mixed, mixed>  $candidate  Nested value from a schema-bearing position.
     *
     * @return  array<string, mixed>  Identical value after validating every member name.
     *
     * @throws  LogicException  When a catalogue puts a JSON list in a schema-object position.
     *
     * @since   2.0.0
     */
    private static function stringKeyed(array $candidate): array
    {
        $schema = [];
        foreach ($candidate as $key => $value) {
            if (!is_string($key)) {
                throw new LogicException('An MCP JSON Schema object contains a non-string member name.');
            }
            $schema[$key] = $value;
        }

        return $schema;
    }
}
