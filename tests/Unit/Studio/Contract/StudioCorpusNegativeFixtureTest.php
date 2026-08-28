<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Contract;

use Kumwe\App\Studio\Domain\Contract\SchemaPropertyValidator;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Replays the vendored `invalid/` corpus group against the compiled runtime schema registry.
 *
 * Finding V2-STU-002 requires the vendored corpus to be replayed here, not merely digest-verified:
 * `composer studio:corpus` proves the negative fixtures are the pinned bytes, but only execution
 * proves the App's validator refuses what the contract publishes as invalid. Every fixture names
 * its target schema, so the group partitions on {@see StudioContractSchemas::DOCUMENT_KINDS}: a
 * fixture aimed at a runtime document kind must be rejected by the same compiled validator the
 * host boundary runs, and a fixture aimed at any other kind must be refused wholesale, because the
 * registry's closure is closed — command, media, host-capability, testkit-envelope, rich-text,
 * typed-value and theme documents are interpreted by Producer's wire and renderer layers and their
 * replay arrives with the `kumwe/producer` adoption pin, never as an App-side reimplementation.
 *
 * @since  2.0.0
 */
#[CoversClass(SchemaPropertyValidator::class)]
#[CoversClass(StudioContractSchemas::class)]
final class StudioCorpusNegativeFixtureTest extends TestCase
{
    /**
     * A negative fixture aimed at a runtime document kind is rejected with at least one diagnostic.
     *
     * @param   string  $file  Fixture document path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('runtimeKindFixtures')]
    public function testEveryRuntimeKindNegativeFixtureIsRejected(string $file): void
    {
        $fixture = self::decode($file);
        $validator = StudioContractSchemas::fromVendoredCorpus()->validator(self::kind($fixture));

        self::assertFalse(
            $validator->validate($fixture->value),
            sprintf('%s is published as invalid and must be rejected server-side.', basename($file)),
        );
        $diagnostics = $validator->diagnostics();
        self::assertNotNull($diagnostics, 'A rejection must carry its diagnostics.');
        self::assertNotSame([], $diagnostics, 'A rejection must name at least one violated position.');
    }

    /**
     * A negative fixture aimed at a non-runtime kind cannot even obtain a validator here.
     *
     * The closed registry is the assertion: these documents belong to Producer's wire and renderer
     * layers, so the App refuses the whole kind instead of approximating a paraphrased validator.
     *
     * @param   string  $file  Fixture document path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('foreignKindFixtures')]
    public function testEveryForeignKindNegativeFixtureIsRefusedByTheClosedRegistry(string $file): void
    {
        $kind = self::kind(self::decode($file));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('"%s" is not a supported canonical Studio document kind.', $kind));

        StudioContractSchemas::fromVendoredCorpus()->validator($kind);
    }

    /**
     * The two replay arms partition exactly the manifest's `invalid-fixtures` group, nothing skipped.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheReplayPartitionCoversTheManifestGroupExactly(): void
    {
        $runtime = iterator_count(self::runtimeKindFixtures());
        $foreign = iterator_count(self::foreignKindFixtures());

        self::assertGreaterThan(0, $runtime, 'The pinned corpus must exercise at least one runtime kind.');
        self::assertGreaterThan(0, $foreign, 'The pinned corpus must exercise the closed-registry arm.');
        self::assertSame(
            self::manifestGroupSize('invalid-fixtures'),
            $runtime + $foreign,
            'Every digest-verified negative fixture must be replayed by exactly one arm.',
        );
    }

    /**
     * Negative fixtures whose target schema is a runtime document kind the registry compiles.
     *
     * @return  iterable<string, array{0: string}>  Fixture paths keyed by file name.
     *
     * @since   2.0.0
     */
    public static function runtimeKindFixtures(): iterable
    {
        yield from self::fixtures(true);
    }

    /**
     * Negative fixtures whose target schema lies outside the closed runtime closure.
     *
     * @return  iterable<string, array{0: string}>  Fixture paths keyed by file name.
     *
     * @since   2.0.0
     */
    public static function foreignKindFixtures(): iterable
    {
        yield from self::fixtures(false);
    }

    /**
     * Partition the vendored `invalid/` directory on the registry's runtime document kinds.
     *
     * @param   bool  $runtime  Whether to yield the runtime-kind arm or the foreign-kind arm.
     *
     * @return  iterable<string, array{0: string}>  Fixture paths keyed by file name.
     *
     * @since   2.0.0
     */
    private static function fixtures(bool $runtime): iterable
    {
        $files = glob(self::corpusRoot() . '/testkit/invalid/*.json');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'The vendored negative-fixture directory must not be empty.');
        foreach ($files as $file) {
            $kind = self::kind(self::decode($file));
            if (in_array($kind, StudioContractSchemas::DOCUMENT_KINDS, true) === $runtime) {
                yield basename($file) => [$file];
            }
        }
    }

    /**
     * Resolve the document kind one fixture publishes through its `schema` member.
     *
     * @param   stdClass  $fixture  Decoded negative fixture.
     *
     * @return  string  Schema file name with the `.schema.json` suffix removed.
     *
     * @since   2.0.0
     */
    private static function kind(stdClass $fixture): string
    {
        $schema = $fixture->schema ?? null;
        self::assertIsString($schema, 'Every negative fixture names its target schema.');
        $kind = preg_replace('/\.schema\.json$/', '', $schema);
        self::assertIsString($kind);
        self::assertNotSame('', $kind);

        return $kind;
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
