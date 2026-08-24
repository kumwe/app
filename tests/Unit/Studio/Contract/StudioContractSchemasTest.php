<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Contract;

use FilesystemIterator;
use Kumwe\App\Extension\Domain\Internal\StudioProfile\StudioContributionSchemas;
use Kumwe\App\Studio\Domain\Contract\SchemaPropertyValidator;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Prove the vendored Studio schema registry compiles the pinned closure and refuses every escape.
 *
 * The registry is the executable form of the pinned protocol corpus: it must interpret exactly the
 * keywords the reviewed interpreter supports, keep every reference inside the vendored closure, and
 * refuse a corpus that drifts — a missing document, a duplicated identity, an unsupported keyword or
 * an unresolvable reference. Each refusal here poisons one copy of the real corpus, so the test
 * proves the arm the compiler takes, not a synthetic approximation of the corpus.
 *
 * @since  2.0.0
 */
#[CoversClass(SchemaPropertyValidator::class)]
#[CoversClass(StudioContributionSchemas::class)]
#[CoversClass(StudioContractSchemas::class)]
final class StudioContractSchemasTest extends TestCase
{
    /**
     * Directories created for poisoned corpus copies, removed again after each test.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $temporaryCorpora = [];

    /**
     * Remove every poisoned corpus copy a test created.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryCorpora as $directory) {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $file) {
                @unlink((string) $file);
            }
            @rmdir($directory);
        }
        $this->temporaryCorpora = [];
    }

    /**
     * The vendored corpus compiles into one registry serving every runtime document kind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheVendoredCorpusCompilesAValidatorForEveryRuntimeDocumentKind(): void
    {
        $registry = StudioContractSchemas::fromVendoredCorpus(self::corpusDirectory());

        foreach (StudioContractSchemas::DOCUMENT_KINDS as $kind) {
            $validator = $registry->validator($kind);
            self::assertSame($validator, $registry->validator($kind));
        }

        $document = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 3)
                    . '/Fixtures/ExtensionApi/generations/manifest-6/kumwe.json',
            ),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($document);
        $declared = $document['contributions']['composition']['documents'] ?? null;
        self::assertIsArray($declared);
        foreach ($declared as $entry) {
            self::assertIsArray($entry);
            $kind = $entry['kind'];
            $canonical = $entry['canonical'];
            self::assertIsString($kind);
            self::assertIsString($canonical);
            $decoded = json_decode($canonical, false, 64, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $decoded);
            self::assertTrue(
                $registry->validator($kind)->validate($decoded),
                sprintf('The signed %s fixture document fails its own pinned schema.', $kind),
            );
        }
    }

    /**
     * The shared vendored compilation is cached: two default calls return one registry instance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDefaultVendoredCompilationIsShared(): void
    {
        self::assertSame(
            StudioContractSchemas::fromVendoredCorpus(),
            StudioContractSchemas::fromVendoredCorpus(),
        );
    }

    /**
     * Asking for a validator outside the closed runtime-document set is a programming error.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAValidatorOutsideTheRuntimeDocumentsIsRefused(): void
    {
        $registry = StudioContractSchemas::fromVendoredCorpus(self::corpusDirectory());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"media-asset" is not a supported canonical Studio document kind.');
        $registry->validator('media-asset');
    }

    /**
     * The former extension-internal registry remains contribution-only over the neutral compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheExtensionInternalRegistryRemainsARestrictedAdapter(): void
    {
        $legacy = StudioContributionSchemas::fromVendoredCorpus(self::corpusDirectory());

        self::assertInstanceOf(SchemaPropertyValidator::class, $legacy->validator('pattern'));
        self::assertSame(StudioContractSchemas::CONTRIBUTION_KINDS, StudioContributionSchemas::CONTRIBUTION_KINDS);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"content-model" is not a canonical Studio contribution kind.');
        $legacy->validator('content-model');
    }

    /**
     * Every way a corpus can drift from the pinned closure is refused with its own message.
     *
     * Each case copies the real corpus and poisons exactly one document, so the compiler reads a
     * closure that is valid everywhere except the arm under test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPoisonedCorpusIsRefusedAtTheArmItBreaks(): void
    {
        $cases = [
            'missing document' => [
                ['migration' => null],
                'is missing or unreadable',
            ],
            'missing root $id' => [
                ['migration' => '{"$schema": "https://json-schema.org/draft/2020-12/schema"}'],
                'must declare a root $id',
            ],
            'duplicated $id' => [
                [
                    'migration' => '{"$id": "https://schemas.kumwe.org/studio/v1/inspector.schema.json"'
                        . ', "$schema": "https://json-schema.org/draft/2020-12/schema"}',
                ],
                'declares https://schemas.kumwe.org/studio/v1/inspector.schema.json more than once',
            ],
            'non-object schema position' => [
                [self::poisonedRoot(['type' => 'object', 'properties' => ['broken' => 'text']])],
                'must be a plain JSON Schema object',
            ],
            'unsupported keyword' => [
                [self::poisonedRoot(['unevaluatedProperties' => false])],
                'which the Studio schema interpreter does not support',
            ],
            'nested $id' => [
                [self::poisonedRoot(['properties' => ['x' => ['$id' => 'https://other.example/x']]])],
                'may only appear at the document root',
            ],
            'foreign dialect' => [
                [self::poisonedRoot([], schema: 'https://json-schema.org/draft-07/schema')],
                'must declare JSON Schema Draft 2020-12',
            ],
            'unbounded reference' => [
                [self::poisonedRoot(['properties' => ['x' => ['$ref' => str_repeat('a', 501)]]])],
                'must be a bounded reference string',
            ],
            'non-object schema map' => [
                [self::poisonedRoot(['$defs' => ['plain']])],
                'must be an object of schemas',
            ],
            'sparse schema array' => [
                [self::poisonedRoot(['allOf' => []])],
                'must be a dense, non-empty array of schemas',
            ],
            'unbounded pattern' => [
                [self::poisonedRoot(['properties' => ['x' => ['pattern' => str_repeat('a', 501)]]])],
                'must be a lexical pattern of at most 500 characters',
            ],
            'broken pattern' => [
                [self::poisonedRoot(['properties' => ['x' => ['pattern' => '(unclosed']]])],
                'is not a valid Unicode regular expression',
            ],
            'escaping reference' => [
                [self::poisonedRoot(['properties' => ['x' => ['$ref' => '../outside.schema.json']]])],
                'must stay within the schema registry root',
            ],
            'unregistered document' => [
                [self::poisonedRoot(['properties' => ['x' => ['$ref' => 'stranger.schema.json']]])],
                'which is not in the registry',
            ],
            'plain-name fragment' => [
                [self::poisonedRoot(['properties' => ['x' => ['$ref' => '#anchor']]])],
                'must use a JSON Pointer fragment',
            ],
            'broken pointer escape' => [
                [self::poisonedRoot(['properties' => ['x' => ['$ref' => '#/$defs/a~2b']]])],
                'is not a valid JSON Pointer reference',
            ],
            'non-schema position' => [
                [self::poisonedRoot(['properties' => ['x' => ['$ref' => '#/$defs/absent']]])],
                'does not reference a schema position',
            ],
        ];

        foreach ($cases as $label => [$mutations, $message]) {
            $directory = $this->corpusWith($mutations);
            $refused = null;
            try {
                StudioContractSchemas::fromVendoredCorpus($directory);
            } catch (RuntimeException $exception) {
                $refused = $exception;
            }
            self::assertNotNull($refused, sprintf('The %s corpus compiled.', $label));
            self::assertStringContainsString(
                $message,
                $refused->getMessage(),
                sprintf('The %s corpus was refused at a different arm.', $label),
            );
        }
    }

    /**
     * ECMAScript Unicode escapes compile to PCRE while even backslash runs stay literal text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPatternCompilationTranslatesOnlyRealUnicodeEscapes(): void
    {
        $directory = $this->corpusWith([
            'migration' => self::encode(self::documentWith([
                'properties' => [
                    'x' => ['pattern' => '^[^\\u0000-\\u001F]*$'],
                    'y' => ['pattern' => '^\\\\u0041$'],
                    'z' => ['pattern' => '^\\u{1F600}$'],
                ],
            ])),
        ]);

        $registry = StudioContractSchemas::fromVendoredCorpus($directory);
        $validator = $registry->validator('migration');
        self::assertFalse($validator->validate((object) ['x' => "a\u{0001}b"]));
        self::assertTrue($validator->validate((object) ['x' => 'plain']));
        self::assertTrue($validator->validate((object) ['y' => '\\u0041']));
        self::assertFalse($validator->validate((object) ['y' => 'A']));
        self::assertTrue($validator->validate((object) ['z' => "\u{1F600}"]));
    }

    /**
     * The vendored corpus directory the registry defaults to.
     *
     * @return  string  Absolute schema directory path.
     *
     * @since   2.0.0
     */
    private static function corpusDirectory(): string
    {
        return dirname(__DIR__, 4) . '/resources/studio-contract/protocol/schemas';
    }

    /**
     * Copy the real corpus and replace, poison, or delete named documents.
     *
     * @param   array<int|string, mixed>  $mutations  Document name to replacement JSON (or null to
     *          delete); an integer key holds a [name, json] pair from a poisoning helper.
     *
     * @return  string  Directory of the poisoned corpus copy.
     *
     * @since   2.0.0
     */
    private function corpusWith(array $mutations): string
    {
        $directory = sys_get_temp_dir() . '/kumwe-studio-corpus-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->temporaryCorpora[] = $directory;
        $iterator = new FilesystemIterator(self::corpusDirectory(), FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            copy((string) $file, $directory . '/' . basename((string) $file));
        }
        foreach ($mutations as $key => $value) {
            [$name, $json] = is_int($key) ? $value : [$key, $value];
            $path = $directory . '/' . $name . '.schema.json';
            if ($json === null) {
                unlink($path);
                continue;
            }
            file_put_contents($path, $json);
        }

        return $directory;
    }

    /**
     * Poison the migration document with extra root members while keeping its identity valid.
     *
     * @param   array<string, mixed>  $members  Extra root members merged over the minimal document.
     * @param   string                $schema   Dialect the poisoned document declares.
     *
     * @return  array{0: string, 1: string}  Document name and its JSON body.
     *
     * @since   2.0.0
     */
    private static function poisonedRoot(
        array $members,
        string $schema = 'https://json-schema.org/draft/2020-12/schema',
    ): array {
        return ['migration', self::encode(self::documentWith($members, $schema))];
    }

    /**
     * Build the minimal valid migration replacement document with extra members merged in.
     *
     * @param   array<string, mixed>  $members  Extra root members.
     * @param   string                $schema   Dialect the document declares.
     *
     * @return  array<string, mixed>  The complete document.
     *
     * @since   2.0.0
     */
    private static function documentWith(
        array $members,
        string $schema = 'https://json-schema.org/draft/2020-12/schema',
    ): array {
        return array_merge([
            '$id' => 'https://schemas.kumwe.org/studio/v1/migration.schema.json',
            '$schema' => $schema,
            'type' => 'object',
        ], $members);
    }

    /**
     * Encode one poisoned document as JSON.
     *
     * @param   array<string, mixed>  $document  The document to encode.
     *
     * @return  string  JSON body.
     *
     * @since   2.0.0
     */
    private static function encode(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
