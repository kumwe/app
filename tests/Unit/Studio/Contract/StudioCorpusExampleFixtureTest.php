<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Contract;

use Kumwe\App\Studio\Domain\Contract\SchemaPropertyValidator;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Replays the vendored `fixtures/` example corpus group against the compiled runtime registry.
 *
 * Finding V2-STU-002 requires the vendored corpus to be replayed here, not merely digest-verified.
 * The example fixtures are the contract's published positive documents, so the arm the App owns is
 * admission: every example whose `kind` is a runtime document kind must pass exactly the compiled
 * pinned validator the host boundary runs, proving the boundary admits what the contract publishes
 * as valid. Examples of foreign kinds — commands, the media lifecycle, host capabilities and
 * operations, plugin manifests, provenance, rich-text, themes, configuration and message catalogs,
 * unresolved contributions — are interpreted by Producer's wire and renderer layers, and their
 * replay arrives with the `kumwe/producer` adoption pin; the partition test still counts them so a
 * re-pin cannot grow the group silently.
 *
 * @since  2.0.0
 */
#[CoversClass(SchemaPropertyValidator::class)]
#[CoversClass(StudioContractSchemas::class)]
final class StudioCorpusExampleFixtureTest extends TestCase
{
    /**
     * A published example of a runtime document kind is admitted by its compiled pinned validator.
     *
     * @param   string  $file  Example document path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('runtimeKindExamples')]
    public function testEveryRuntimeKindExampleIsAdmitted(string $file): void
    {
        $document = self::decode($file);
        $kind = $document->kind ?? null;
        self::assertIsString($kind);

        $validator = StudioContractSchemas::fromVendoredCorpus()->validator($kind);
        $admitted = $validator->validate($document);
        $diagnostics = $validator->diagnostics() ?? [];
        $first = $diagnostics[0] ?? null;
        self::assertTrue($admitted, sprintf(
            'This host must admit the published example %s; first diagnostic: %s %s.',
            basename($file),
            $first->instancePath ?? '(none)',
            $first->keyword ?? '',
        ));
    }

    /**
     * The admission arm and the Producer-side remainder partition the manifest group exactly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheReplayPartitionCoversTheManifestGroupExactly(): void
    {
        $runtime = iterator_count(self::runtimeKindExamples());
        $foreign = iterator_count(self::foreignKindExamples());

        self::assertGreaterThan(0, $runtime, 'The pinned corpus must publish runtime-kind examples.');
        self::assertGreaterThan(0, $foreign, 'The pinned corpus must publish Producer-side examples.');
        self::assertSame(
            self::manifestGroupSize('fixtures'),
            $runtime + $foreign,
            'Every digest-verified example must be counted by exactly one arm.',
        );
    }

    /**
     * Example documents whose declared kind is a runtime document kind the registry compiles.
     *
     * @return  iterable<string, array{0: string}>  Example paths keyed by file name.
     *
     * @since   2.0.0
     */
    public static function runtimeKindExamples(): iterable
    {
        yield from self::examples(true);
    }

    /**
     * Example documents outside the runtime closure, interpreted by Producer at the adoption pin.
     *
     * @return  iterable<string, array{0: string}>  Example paths keyed by file name.
     *
     * @since   2.0.0
     */
    public static function foreignKindExamples(): iterable
    {
        yield from self::examples(false);
    }

    /**
     * Partition the vendored `fixtures/` directory on the registry's runtime document kinds.
     *
     * @param   bool  $runtime  Whether to yield the runtime-kind arm or the Producer-side arm.
     *
     * @return  iterable<string, array{0: string}>  Example paths keyed by file name.
     *
     * @since   2.0.0
     */
    private static function examples(bool $runtime): iterable
    {
        $files = glob(self::corpusRoot() . '/testkit/fixtures/*.json');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'The vendored example directory must not be empty.');
        foreach ($files as $file) {
            $kind = self::decode($file)->kind ?? null;
            $interpreted = is_string($kind) && in_array($kind, StudioContractSchemas::DOCUMENT_KINDS, true);
            if ($interpreted === $runtime) {
                yield basename($file) => [$file];
            }
        }
    }

    /**
     * Read the pinned file count of one corpus-manifest group.
     *
     * @param   string  $group  Manifest group identifier.
     *
     * @return  int  Number of digest-verified files the group declares.
     *
     * @since   2.0.0
     */
    private static function manifestGroupSize(string $group): int
    {
        $manifest = self::decode(self::corpusRoot() . '/testkit/corpus-manifest.json');
        foreach ($manifest->groups ?? [] as $candidate) {
            self::assertInstanceOf(stdClass::class, $candidate);
            if (($candidate->group ?? null) !== $group) {
                continue;
            }
            $files = $candidate->files ?? null;
            self::assertIsArray($files);

            return count($files);
        }
        self::fail(sprintf('The corpus manifest declares no group named %s.', $group));
    }

    /**
     * Decode one corpus document with objects kept as objects.
     *
     * @param   string  $file  Document path.
     *
     * @return  stdClass  The decoded document.
     *
     * @since   2.0.0
     */
    private static function decode(string $file): stdClass
    {
        $decoded = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $decoded);

        return $decoded;
    }

    /**
     * Root of the vendored Studio corpus.
     *
     * @return  string  Absolute fixture path.
     *
     * @since   2.0.0
     */
    private static function corpusRoot(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Studio';
    }
}
