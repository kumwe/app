<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\StudioProfile;

use DateTimeImmutable;
use Kumwe\App\Extension\Domain\Internal\StudioProfile\CanonicalJson;
use Kumwe\App\Extension\Domain\Internal\StudioProfile\CanonicalJsonRejected;
use Kumwe\App\Extension\Domain\Internal\StudioProfile\SchemaProfileRejected;
use Kumwe\App\Extension\Domain\Internal\StudioProfile\SchemaPropertyProfile;
use Kumwe\App\Extension\Domain\Internal\StudioProfile\SchemaPropertyValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Exercises the profile edges the published vector corpus leaves untaken.
 *
 * Every corpus vector stops at its first refusal, so many rejection arms, number-grammar corners
 * and validator branches never execute during the replay. The coverage contract is right to call
 * that out: a refusal nothing has taken is a branch nothing has tested. These tests take each of
 * them deliberately — one crafted schema per rejection arm with its exact code and pointer, the
 * ECMAScript number-grammar corners, the UTF-16 code-unit comparison branch, and the instance
 * keywords the accepted vectors do not reach.
 *
 * @since  2.0.0
 */
#[CoversClass(CanonicalJson::class)]
#[CoversClass(SchemaPropertyProfile::class)]
#[CoversClass(SchemaPropertyValidator::class)]
final class StudioSchemaProfileEdgeTest extends TestCase
{
    /**
     * Object members sort by UTF-16 code unit, which differs from UTF-8 byte order for astral text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAstralMembersSortBeforeHigherBasicPlaneMembers(): void
    {
        $value = new stdClass();
        $value->{'ﬁ'} = 1;
        $value->{'😀'} = 2;

        self::assertSame(
            '{"😀":2,"ﬁ":1}',
            CanonicalJson::stringify($value),
            'A surrogate pair (D83D DE00) sorts before U+FB01 by code unit, after it by UTF-8 byte.',
        );
    }

    /**
     * The deterministic number grammar switches notation exactly where ECMAScript does.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNumberGrammarCorners(): void
    {
        self::assertSame('1000', CanonicalJson::encodeNumber(1000.0));
        self::assertSame('1e+21', CanonicalJson::encodeNumber(1e21));
        self::assertSame('-1e+21', CanonicalJson::encodeNumber(-1e21));
        self::assertSame('1.5e+21', CanonicalJson::encodeNumber(1.5e21));
        self::assertSame('1e-7', CanonicalJson::encodeNumber(1e-7));
        self::assertSame('2.5e-7', CanonicalJson::encodeNumber(2.5e-7));
        self::assertSame('0.000001', CanonicalJson::encodeNumber(1e-6));
        self::assertSame('0.5', CanonicalJson::encodeNumber(0.5));
        self::assertSame('0', CanonicalJson::encodeNumber(-0.0));
        self::assertSame('-42', CanonicalJson::encodeNumber(-42));
    }

    /**
     * Values the canonical form cannot carry are refused with the stable `not-json` reason.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnrepresentableValuesAreRefused(): void
    {
        foreach (
            [
                'non-finite number' => INF,
                'associative array' => ['a' => 1],
                'foreign object' => new DateTimeImmutable(),
            ] as $label => $value
        ) {
            try {
                CanonicalJson::stringify($value);
                self::fail(sprintf('A %s must not serialize.', $label));
            } catch (CanonicalJsonRejected $rejection) {
                self::assertSame('not-json', $rejection->reason, $label);
            }
        }
    }

    /**
     * Each admission rejection arm the corpus leaves untaken refuses with its code and pointer.
     *
     * @param   string  $schema        Schema document as JSON.
     * @param   string  $code          Expected closed rejection code.
     * @param   string  $schemaPath    Expected schema JSON Pointer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('rejectionArms')]
    public function testRejectionArmsRefuseWithTheirCodeAndPointer(
        string $schema,
        string $code,
        string $schemaPath,
    ): void {
        $decoded = json_decode($schema, false, 512, JSON_THROW_ON_ERROR);

        try {
            SchemaPropertyProfile::admit($decoded);
            self::fail('The schema must be refused.');
        } catch (SchemaProfileRejected $rejection) {
            self::assertSame($code, $rejection->rejection);
            self::assertSame($schemaPath, $rejection->schemaPath);
        }
    }

    /**
     * One crafted schema per rejection arm, named by what it violates.
     *
     * @return  iterable<string, array{0: string, 1: string, 2: string}>  Schema, code and pointer.
     *
     * @since   2.0.0
     */
    public static function rejectionArms(): iterable
    {
        $root = '"type":"object","additionalProperties":false';
        yield 'schema position holds a scalar' => [
            '{' . $root . ',"items":5}', 'invalid-keyword-value', '/items',
        ];
        yield 'schema map is an array' => [
            '{' . $root . ',"properties":[]}', 'invalid-keyword-value', '/properties',
        ];
        yield 'schema map member is a scalar' => [
            '{' . $root . ',"$defs":{"x":5}}', 'invalid-keyword-value', '/$defs/x',
        ];
        yield 'composition operand is an object' => [
            '{' . $root . ',"allOf":{}}', 'invalid-keyword-value', '/allOf',
        ];
        yield 'enum operand is a scalar' => [
            '{' . $root . ',"enum":5}', 'invalid-keyword-value', '/enum',
        ];
        yield 'enum repeats a canonical value across numeric types' => [
            '{' . $root . ',"enum":[1,1.0]}', 'invalid-keyword-value', '/enum/1',
        ];
        yield 'examples operand is a scalar' => [
            '{' . $root . ',"examples":5}', 'invalid-keyword-value', '/examples',
        ];
        yield 'dependentRequired operand is an array' => [
            '{' . $root . ',"dependentRequired":[]}', 'invalid-keyword-value', '/dependentRequired',
        ];
        yield 'required operand is an object' => [
            '{' . $root . ',"required":{}}', 'invalid-keyword-value', '/required',
        ];
        yield 'required member is not a string' => [
            '{' . $root . ',"required":[5]}', 'invalid-keyword-value', '/required/0',
        ];
        yield 'type names an unknown type' => [
            '{"additionalProperties":false,"type":"text"}', 'invalid-keyword-value', '/type',
        ];
        yield 'type array is empty' => [
            '{"additionalProperties":false,"type":[]}', 'invalid-keyword-value', '/type',
        ];
        yield 'type array repeats a name' => [
            '{' . $root . ',"properties":{"a":{"type":["string","string"]}}}',
            'invalid-keyword-value', '/properties/a/type/1',
        ];
        yield 'reference resolves to a non-schema position' => [
            '{' . $root . ',"title":"x","$ref":"#/title"}', 'invalid-reference', '/$ref',
        ];
        yield 'dialect is not Draft 2020-12' => [
            '{' . $root . ',"$schema":"https://json-schema.org/draft/2019-09/schema"}',
            'invalid-keyword-value', '/$schema',
        ];
        yield 'title is not a string' => [
            '{' . $root . ',"title":5}', 'invalid-keyword-value', '/title',
        ];
        yield 'minLength is negative' => [
            '{' . $root . ',"minLength":-1}', 'invalid-keyword-value', '/minLength',
        ];
        yield 'minimum is not a number' => [
            '{' . $root . ',"minimum":"5"}', 'invalid-keyword-value', '/minimum',
        ];
        yield 'multipleOf is zero' => [
            '{' . $root . ',"multipleOf":0}', 'invalid-keyword-value', '/multipleOf',
        ];
        yield 'readOnly is not a boolean' => [
            '{' . $root . ',"readOnly":"yes"}', 'invalid-keyword-value', '/readOnly',
        ];
        yield 'root type declares a scalar' => [
            '{"additionalProperties":false,"type":"string"}', 'invalid-root', '/type',
        ];
    }

    /**
     * A dependency map past the entry ceiling is refused as `limit-exceeded`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOversizedDependencyMapIsRefused(): void
    {
        $dependencies = new stdClass();
        for ($index = 0; $index <= SchemaPropertyProfile::LIMITS['maxSchemaMapProperties']; $index++) {
            $dependencies->{'p' . $index} = [];
        }
        $schema = self::closedRoot();
        $schema->dependentRequired = $dependencies;

        try {
            SchemaPropertyProfile::admit($schema);
            self::fail('An oversized dependency map must be refused.');
        } catch (SchemaProfileRejected $rejection) {
            self::assertSame('limit-exceeded', $rejection->rejection);
            self::assertSame('/dependentRequired', $rejection->schemaPath);
        }
    }

    /**
     * Values only a hand-built document can carry are still refused rather than serialized.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHandBuiltNonJsonOperandsAreRefused(): void
    {
        $withList = self::closedRoot();
        $withList->const = ['a' => 1];
        try {
            SchemaPropertyProfile::admit($withList);
            self::fail('An associative-array operand must be refused.');
        } catch (SchemaProfileRejected $rejection) {
            self::assertSame('invalid-keyword-value', $rejection->rejection);
            self::assertSame('/const', $rejection->schemaPath);
        }

        $withObject = self::closedRoot();
        $withObject->default = new DateTimeImmutable();
        try {
            SchemaPropertyProfile::admit($withObject);
            self::fail('A non-JSON operand must be refused.');
        } catch (SchemaProfileRejected $rejection) {
            self::assertSame('invalid-keyword-value', $rejection->rejection);
            self::assertSame('/default', $rejection->schemaPath);
        }

        $shared = new stdClass();
        $shared->type = 'string';
        $reusing = self::closedRoot();
        $reusing->properties = new stdClass();
        $reusing->properties->a = $shared;
        $reusing->properties->b = $shared;
        try {
            SchemaPropertyProfile::admit($reusing);
            self::fail('A reused schema object must be refused.');
        } catch (SchemaProfileRejected $rejection) {
            self::assertSame('invalid-root', $rejection->rejection);
            self::assertSame('/properties/b', $rejection->schemaPath);
        }
    }

    /**
     * The instance keywords the accepted vectors leave untaken hold their published semantics.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testValidatorEdgeKeywords(): void
    {
        $validator = SchemaPropertyProfile::admit(json_decode(<<<'JSON'
            {
                "type": "object",
                "additionalProperties": false,
                "properties": {
                    "list": {
                        "type": "array",
                        "prefixItems": [{"type": "integer"}],
                        "items": false,
                        "uniqueItems": true,
                        "minItems": 1,
                        "maxItems": 3
                    },
                    "uniq": {"uniqueItems": true},
                    "word": {"type": "string", "minLength": 2, "maxLength": 4},
                    "count": {"type": ["integer", "null"], "minimum": 1, "exclusiveMaximum": 10},
                    "choice": {"oneOf": [{"minimum": 0}, {"maximum": 100}]},
                    "other": {"not": {"const": 3}, "if": {"minimum": 5}, "else": {"const": 1}}
                },
                "propertyNames": {"maxLength": 6},
                "dependentRequired": {"word": ["count"]},
                "minProperties": 1,
                "maxProperties": 5
            }
            JSON, false, 512, JSON_THROW_ON_ERROR));

        self::assertTrue($validator->validate(json_decode('{"list":[7],"word":"hi","count":9}', false)));

        $failing = [
            'prefix overflow under items false' => ['{"list":[7,8]}', 'items', '/list'],
            'duplicate items' => ['{"uniq":[1,1]}', 'uniqueItems', '/uniq'],
            'string too long' => ['{"word":"toolong","count":1}', 'maxLength', '/word'],
            'exclusive maximum met' => ['{"count":10}', 'exclusiveMaximum', '/count'],
            'oneOf matches both alternatives' => ['{"choice":50}', 'oneOf', '/choice'],
            'negated constant matched' => ['{"other":3}', 'not', '/other'],
            'else branch refused' => ['{"other":2}', 'const', '/other'],
            'dependent member missing' => ['{"word":"hi"}', 'dependentRequired', ''],
            'no properties at all' => ['{}', 'minProperties', ''],
        ];
        foreach ($failing as $label => [$instance, $keyword, $path]) {
            self::assertFalse($validator->validate(json_decode($instance, false)), $label);
            $first = $validator->diagnostics()[0] ?? null;
            self::assertNotNull($first, $label);
            self::assertSame($keyword, $first->keyword, $label);
            self::assertSame($path, $first->instancePath, $label);
        }

        $named = SchemaPropertyProfile::admit(json_decode(
            '{"type":"object","additionalProperties":false,"properties":{"long":{"type":"integer"}},'
            . '"propertyNames":{"maxLength":2}}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        ));
        self::assertFalse($named->validate(json_decode('{"long":1}', false)));
        self::assertSame('propertyNames', $named->diagnostics()[0]->keyword ?? null);

        $closed = SchemaPropertyProfile::admit(json_decode(
            '{"type":"object","additionalProperties":false,'
            . '"properties":{"x":{"prefixItems":[false]}}}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        ));
        self::assertFalse($closed->validate(json_decode('{"x":[1]}', false)));
        self::assertSame('false', $closed->diagnostics()[0]->keyword ?? null);
        self::assertSame('/x/0', $closed->diagnostics()[0]->instancePath ?? null);
        self::assertTrue($closed->validate(json_decode('{}', false)));
    }

    /**
     * Zero is an exact decimal multiple of every divisor, and repeat runs stay deterministic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDecimalMultipleAndMemoDeterminism(): void
    {
        $validator = SchemaPropertyProfile::admit(json_decode(
            '{"type":"object","additionalProperties":false,'
            . '"properties":{"n":{"multipleOf":0.01}},"$defs":{"f":{"required":["missing"]}},'
            . '"anyOf":[{"$ref":"#/$defs/f"},true],"if":true,"then":{"$ref":"#/$defs/f"}}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        ));
        $zero = json_decode('{"n":0}', false);
        self::assertFalse($validator->validate($zero), 'The then-branch still demands the member.');
        self::assertSame('required', $validator->diagnostics()[0]->keyword ?? null);
        $first = $validator->diagnostics();
        self::assertFalse($validator->validate($zero), 'A repeat run reaches the same verdict.');
        self::assertEquals($first, $validator->diagnostics(), 'Diagnostics are deterministic across runs.');
    }

    /**
     * The remaining applicator branches: type lists, combinators, items, additional properties.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testApplicatorBranchesRefuseTheirMismatches(): void
    {
        $root = self::closedRoot();
        $root->properties = json_decode((string) json_encode([
            'union' => ['type' => ['string', 'null']],
            'both' => ['allOf' => [['type' => 'string'], ['minLength' => 3]]],
            'either' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
            'flag' => ['type' => 'boolean'],
            'sized' => ['type' => 'string', 'minLength' => 2],
            'low' => ['type' => 'number', 'maximum' => 10, 'exclusiveMinimum' => 0],
            'pair' => ['prefixItems' => [['type' => 'string']], 'items' => false],
            'tail' => ['prefixItems' => [['type' => 'string']], 'items' => ['type' => 'integer']],
            'filled' => ['type' => 'array', 'minItems' => 2],
            'map' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            'small' => ['type' => 'object', 'maxProperties' => 1],
        ]), false);
        $validator = SchemaPropertyProfile::admit($root);

        $failures = [
            'type list' => [['union' => 5], '/union', 'must be string,null'],
            'allOf' => [['both' => 5], '/both', 'must match all schemas in allOf'],
            'anyOf' => [['either' => true], '/either', 'must match a schema in anyOf'],
            'minLength' => [['sized' => 'a'], '/sized', 'must NOT have fewer than 2 characters'],
            'maximum' => [['low' => 11], '/low', 'must be <= 10'],
            'exclusiveMinimum' => [['low' => 0], '/low', 'must be > 0'],
            'closed tuple' => [['pair' => ['a', 'b']], '/pair', 'must NOT have more than 1 items'],
            'typed tail' => [['tail' => ['a', 'b']], '/tail/1', 'must be integer'],
            'minItems' => [['filled' => []], '/filled', 'must NOT have fewer than 2 items'],
            'typed additional' => [['map' => ['x' => 'no']], '/map/x', 'must be integer'],
            'maxProperties' => [
                ['small' => ['a' => 1, 'b' => 2]],
                '/small',
                'must NOT have more than 1 properties',
            ],
        ];
        foreach ($failures as $label => [$instance, $path, $message]) {
            $decoded = json_decode((string) json_encode($instance), false);
            self::assertInstanceOf(stdClass::class, $decoded);
            self::assertFalse($validator->validate($decoded), sprintf('The %s instance passed.', $label));
            $diagnostics = $validator->diagnostics();
            self::assertNotNull($diagnostics);
            $found = false;
            foreach ($diagnostics as $diagnostic) {
                if ($diagnostic->instancePath === $path && $diagnostic->message === $message) {
                    $found = true;
                    break;
                }
            }
            self::assertTrue($found, sprintf('The %s failure did not surface at %s.', $label, $path));
        }

        $decoded = json_decode('{"union":"text","flag":true,"low":5,"pair":["a"],"tail":["a",1,2]}', false);
        self::assertInstanceOf(stdClass::class, $decoded);
        self::assertTrue($validator->validate($decoded), 'The matching instance must pass every branch.');
    }

    /**
     * Structured `const` and `enum` operands compare by deep equality, and twins deduplicate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStructuredEqualityAndDiagnosticDeduplication(): void
    {
        $root = self::closedRoot();
        $root->properties = json_decode((string) json_encode([
            'exact' => ['const' => [1, ['a' => true]]],
            'pick' => ['enum' => [null, true, ['b' => 2], [1, 2]]],
            'twice' => ['allOf' => [['type' => 'string'], ['type' => 'string']]],
        ]), false);
        $validator = SchemaPropertyProfile::admit($root);

        $matching = json_decode('{"exact":[1,{"a":true}],"pick":[1,2]}', false);
        self::assertInstanceOf(stdClass::class, $matching);
        self::assertTrue($validator->validate($matching), 'Deep-equal structures must satisfy const and enum.');

        $mismatch = json_decode('{"exact":[1,{"a":false}],"pick":{"b":3}}', false);
        self::assertInstanceOf(stdClass::class, $mismatch);
        self::assertFalse($validator->validate($mismatch));

        $duplicated = json_decode('{"twice":5}', false);
        self::assertInstanceOf(stdClass::class, $duplicated);
        self::assertFalse($validator->validate($duplicated));
        $diagnostics = $validator->diagnostics();
        self::assertNotNull($diagnostics);
        $typeFailures = array_values(array_filter(
            $diagnostics,
            static fn ($diagnostic): bool => $diagnostic->instancePath === '/twice'
                && $diagnostic->message === 'must be string',
        ));
        self::assertCount(1, $typeFailures, 'Twin diagnostics from twin subschemas deduplicate.');
    }

    /**
     * Reference resolution walks every operand family and the closure visits a shared target once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReferenceResolutionWalksEveryOperandFamily(): void
    {
        $shared = self::closedRoot();
        $shared->{'$defs'} = json_decode((string) json_encode(['name' => ['type' => 'string']]), false);
        $shared->properties = json_decode((string) json_encode([
            'first' => ['$ref' => '#/$defs/name'],
            'second' => ['$ref' => '#/$defs/name'],
        ]), false);
        $validator = SchemaPropertyProfile::admit($shared);
        $decoded = json_decode('{"first":"a","second":"b"}', false);
        self::assertInstanceOf(stdClass::class, $decoded);
        self::assertTrue($validator->validate($decoded), 'A twice-referenced definition resolves once.');

        $arrayTarget = self::closedRoot();
        $arrayTarget->allOf = json_decode((string) json_encode([['type' => 'object']]), false);
        $arrayTarget->properties = json_decode((string) json_encode([
            'inner' => ['$ref' => '#/allOf/0'],
        ]), false);
        $validator = SchemaPropertyProfile::admit($arrayTarget);
        $decoded = json_decode('{"inner":{}}', false);
        self::assertInstanceOf(stdClass::class, $decoded);
        self::assertTrue($validator->validate($decoded), 'A schema-array position is referenceable.');

        $whole = self::closedRoot();
        $whole->properties = json_decode((string) json_encode([
            'loop' => ['$ref' => '#'],
        ]), false);
        $refused = null;
        try {
            SchemaPropertyProfile::admit($whole);
        } catch (SchemaProfileRejected $rejection) {
            $refused = $rejection;
        }
        self::assertNotNull($refused, 'A whole-document self reference must not admit.');
    }

    /**
     * The serializer's remaining scalar branches: null members, and non-finite refusals.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSerializerNullAndNonFiniteBranches(): void
    {
        $value = new stdClass();
        $value->gap = null;
        self::assertSame('{"gap":null}', CanonicalJson::stringify($value));

        $refused = null;
        try {
            CanonicalJson::encodeNumber(INF);
        } catch (CanonicalJsonRejected $rejection) {
            $refused = $rejection;
        }
        self::assertNotNull($refused, 'A non-finite float must refuse encoding.');
        self::assertSame('not-json', $refused->reason);

        $poisoned = self::closedRoot();
        $poisoned->properties = new stdClass();
        $poisoned->properties->x = new stdClass();
        $poisoned->properties->x->enum = [INF];
        $rejected = null;
        try {
            SchemaPropertyProfile::admit($poisoned);
        } catch (SchemaProfileRejected $rejection) {
            $rejected = $rejection;
        }
        self::assertNotNull($rejected, 'A schema carrying a non-finite number must not admit.');
    }

    /**
     * A closed object root ready to be extended by one keyword under test.
     *
     * @return  stdClass  The minimal admissible root.
     *
     * @since   2.0.0
     */
    private static function closedRoot(): stdClass
    {
        $root = new stdClass();
        $root->type = 'object';
        $root->additionalProperties = false;

        return $root;
    }
}
