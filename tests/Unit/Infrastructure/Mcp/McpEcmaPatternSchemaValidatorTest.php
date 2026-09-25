<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use Kumwe\App\Infrastructure\Mcp\McpEcmaPatternSchemaValidator;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Proves MCP tool arguments are validated with the JSON Schema meaning the catalogue advertises.
 *
 * The catalogue's control-character guard is an ECMA-262 pattern the SDK's PCRE validator cannot compile, and
 * an empty JSON object reaches the SDK as an empty PHP array. Both used to refuse valid calls before any
 * handler ran; both are read as JSON Schema defines them here, while invalid values are still refused.
 *
 * @since  2.0.0
 */
#[CoversClass(McpEcmaPatternSchemaValidator::class)]
final class McpEcmaPatternSchemaValidatorTest extends TestCase
{
    /**
     * The catalogue's record-identifier guard, exactly as advertised.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'record' => [
                'anyOf' => [
                    ['type' => 'string', 'minLength' => 1, 'pattern' => '^[^\\u0000-\\u001F\\u007F]+$'],
                    ['type' => 'null'],
                ],
            ],
            'input' => ['type' => 'object', 'additionalProperties' => true],
            'ids' => ['type' => 'array', 'items' => ['type' => 'string']],
            'rows' => ['type' => 'array', 'items' => ['type' => 'object']],
            'options' => ['anyOf' => [['type' => 'object'], ['type' => 'null']]],
            'either' => ['anyOf' => [['type' => 'object'], ['type' => 'array']]],
            'named' => [
                'type' => 'object',
                'patternProperties' => ['^\\u0061[a-z]*$' => ['type' => 'integer']],
                'additionalProperties' => false,
            ],
            'literal' => ['type' => 'string', 'pattern' => '^\\\\u0041$', 'enum' => ['\\u0041', 'x']],
        ],
    ];

    /**
     * The SDK validator refuses the advertised pattern; the Kumwe validator accepts the valid call.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdvertisedEcmaPatternsValidateAsTheyAreWritten(): void
    {
        $arguments = ['record' => 'INV-0001', 'input' => []];

        self::assertNotSame([], (new SchemaValidator())->validateAgainstJsonSchema($arguments, self::SCHEMA));
        self::assertSame([], (new McpEcmaPatternSchemaValidator())->validateAgainstJsonSchema(
            $arguments,
            self::SCHEMA,
        ));
    }

    /**
     * Control characters, wrongly typed empties and misnamed properties are still refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidArgumentsAreStillRefused(): void
    {
        $validator = new McpEcmaPatternSchemaValidator();

        foreach (
            [
                ['record' => "INV\x01"],
                ['record' => "INV\x7F"],
                ['input' => 'text'],
                ['rows' => [[]], 'ids' => ['a', 1]],
                ['named' => ['b' => 1]],
                ['named' => ['a' => 'one']],
                ['literal' => 'x'],
            ] as $arguments
        ) {
            self::assertNotSame(
                [],
                $validator->validateAgainstJsonSchema($arguments, self::SCHEMA),
                (string) json_encode($arguments),
            );
        }
    }

    /**
     * Empty objects are restored only where the schema admits an object and no array.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEmptyArraysBecomeObjectsOnlyWhereTheSchemaRequiresAnObject(): void
    {
        $validator = new McpEcmaPatternSchemaValidator();

        self::assertSame([], $validator->validateAgainstJsonSchema([
            'input' => [],
            'options' => [],
            'rows' => [[], ['x' => 1]],
            'ids' => [],
            'either' => [],
            'named' => ['abc' => 3],
            'literal' => '\\u0041',
        ], self::SCHEMA));
        self::assertSame([], $validator->validateAgainstJsonSchema(new stdClass(), (object) ['type' => 'object']));
    }

    /**
     * Boolean subschemas keep their meaning through the pattern rewrite and the empty-object restoration.
     *
     * JSON Schema allows `true` and `false` wherever a subschema may stand. A `false` pattern property still
     * forbids every name its rewritten pattern matches, and a `false` branch of an `anyOf` is skipped when
     * deciding that an empty value must be the object the other branch admits.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBooleanSubschemasKeepTheirMeaningThroughTheRewrite(): void
    {
        $validator = new McpEcmaPatternSchemaValidator();
        $schema = [
            'type' => 'object',
            'properties' => [
                'headers' => ['type' => 'object', 'patternProperties' => ['^\\u0078-' => false]],
                'options' => ['anyOf' => [false, ['type' => 'object', 'maxProperties' => 0]]],
            ],
        ];

        self::assertSame([], $validator->validateAgainstJsonSchema(
            ['headers' => ['accept' => 'json'], 'options' => []],
            $schema,
        ));
        self::assertNotSame([], $validator->validateAgainstJsonSchema(
            ['headers' => ['x-secret' => 'value']],
            $schema,
        ), 'A name the forbidding pattern matches is refused.');
    }

    /**
     * Only unescaped `\uXXXX` sequences are rewritten; an escaped backslash before `u` stays literal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyUnescapedUnicodeEscapesAreRewritten(): void
    {
        self::assertSame('^[^\\x{0000}-\\x{001F}]+$', McpEcmaPatternSchemaValidator::pcrePattern(
            '^[^\\u0000-\\u001F]+$',
        ));
        self::assertSame('^\\\\u0041\\d$', McpEcmaPatternSchemaValidator::pcrePattern('^\\\\u0041\\d$'));
        self::assertSame('^\\u00G1$', McpEcmaPatternSchemaValidator::pcrePattern('^\\u00G1$'));
    }
}
