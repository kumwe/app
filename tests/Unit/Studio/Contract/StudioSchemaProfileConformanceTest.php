<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Contract;

use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Contract\CanonicalJsonRejected;
use Kumwe\App\Studio\Domain\Contract\SchemaProfileRejected;
use Kumwe\App\Studio\Domain\Contract\SchemaPropertyProfile;
use Kumwe\App\Studio\Domain\Contract\SchemaPropertyValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Replays the pinned Studio contract corpus against the independent PHP implementation.
 *
 * Issue kumwe/app#104 requires an independent PHP implementation of the canonical serialization
 * and the complete `studio.profile/schema-property` assertion set, proven by the language-neutral
 * vectors of the pinned `@kumwe/studio-testkit` release. These tests replay every vendored
 * canonical vector byte for byte and every schema-profile vector both ways: a rejected schema must
 * fail with the published code and schema pointer, and an accepted schema's instance verdicts and
 * first diagnostics must match the published expectation exactly. The profile's complexity limits
 * are additionally pinned to `$defs/limits` in the vendored meta-schema, so this implementation
 * and the reference cannot drift apart silently.
 *
 * @since  2.0.0
 */
#[CoversClass(CanonicalJson::class)]
#[CoversClass(SchemaPropertyProfile::class)]
#[CoversClass(SchemaPropertyValidator::class)]
final class StudioSchemaProfileConformanceTest extends TestCase
{
    /**
     * Replay one canonical vector: exact bytes and digest, or the published refusal.
     *
     * @param   string  $file  Vector document path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('canonicalVectors')]
    public function testCanonicalVectorReplays(string $file): void
    {
        $vector = self::decode($file);
        $maximumDepth = $vector->maximumDepth ?? CanonicalJson::DEFAULT_MAXIMUM_DEPTH;
        self::assertIsInt($maximumDepth);

        try {
            $canonical = CanonicalJson::stringify($vector->value, $maximumDepth);
        } catch (CanonicalJsonRejected $rejection) {
            self::assertSame(
                $vector->expect->rejected ?? null,
                $rejection->reason,
                'The canonical form must refuse exactly what the vector expects.',
            );

            return;
        }
        self::assertObjectNotHasProperty('rejected', $vector->expect, 'An expected refusal was accepted.');
        self::assertSame($vector->expect->canonical, $canonical, 'Canonical bytes must match exactly.');
        self::assertSame(
            $vector->expect->digest,
            'sha256-' . base64_encode(hash('sha256', $canonical, true)),
            'The SRI digest is computed over exactly the canonical bytes.',
        );
    }

    /**
     * Replay one schema-profile vector: admission verdict, code, pointer and instance diagnostics.
     *
     * @param   string  $file  Vector document path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('schemaProfileVectors')]
    public function testSchemaProfileVectorReplays(string $file): void
    {
        $vector = self::decode($file);
        $expect = $vector->expect;

        try {
            $validator = SchemaPropertyProfile::admit($vector->schema);
        } catch (SchemaProfileRejected $rejection) {
            self::assertSame('rejected', $expect->outcome, 'An expected acceptance was rejected.');
            self::assertSame($expect->code, $rejection->rejection, 'The closed rejection code must match.');
            self::assertSame($expect->schemaPath, $rejection->schemaPath, 'The schema pointer must match.');

            return;
        }
        self::assertSame('accepted', $expect->outcome, 'An expected rejection was admitted.');

        foreach ($expect->instances ?? [] as $index => $case) {
            self::assertInstanceOf(stdClass::class, $case);
            $valid = $validator->validate($case->value ?? null);
            self::assertSame(
                $case->valid,
                $valid,
                sprintf('Instance %d of %s must reach the published verdict.', (int) $index, basename($file)),
            );
            if (!isset($case->diagnostic)) {
                continue;
            }
            $first = $validator->diagnostics()[0] ?? null;
            self::assertNotNull($first, 'A failing instance must carry its first diagnostic.');
            self::assertSame($case->diagnostic->instancePath, $first->instancePath);
            self::assertSame($case->diagnostic->keyword, $first->keyword);
        }
    }

    /**
     * The implementation's limits are exactly the vendored meta-schema's `$defs/limits`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLimitsMatchThePinnedMetaSchema(): void
    {
        $metaSchema = self::decode(
            dirname(__DIR__, 4) . '/resources/studio-contract/protocol/schemas/schema-profile.schema.json',
        );
        $published = $metaSchema->{'$defs'}->limits->const ?? null;
        self::assertInstanceOf(stdClass::class, $published);

        $expected = [];
        foreach (get_object_vars($published) as $limit => $value) {
            self::assertIsInt($value, sprintf('Published limit %s must be an integer.', $limit));
            $expected[$limit] = $value;
        }
        ksort($expected);
        $actual = SchemaPropertyProfile::LIMITS;
        ksort($actual);
        self::assertSame($expected, $actual, 'The limits must pin to the vendored meta-schema exactly.');
    }

    /**
     * Every vendored canonical vector document.
     *
     * @return  iterable<string, array{0: string}>  Vector paths keyed by file name.
     *
     * @since   2.0.0
     */
    public static function canonicalVectors(): iterable
    {
        yield from self::vectors('/testkit/vectors/canonical');
    }

    /**
     * Every vendored schema-profile vector document.
     *
     * @return  iterable<string, array{0: string}>  Vector paths keyed by file name.
     *
     * @since   2.0.0
     */
    public static function schemaProfileVectors(): iterable
    {
        yield from self::vectors('/testkit/vectors/schema-profile');
    }

    /**
     * List one vendored vector directory.
     *
     * @param   string  $directory  Corpus-relative directory.
     *
     * @return  iterable<string, array{0: string}>  Vector paths keyed by file name.
     *
     * @since   2.0.0
     */
    private static function vectors(string $directory): iterable
    {
        $files = glob(self::corpusRoot() . $directory . '/*.json');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'The vendored corpus directory must not be empty.');
        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
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
