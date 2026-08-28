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
 * Replays the command-vector corpus group at the App's storage boundary: documents, not commands.
 *
 * Finding V2-STU-002 requires the vendored corpus to be replayed here, not merely digest-verified.
 * This application stores and serves whole Studio artifact documents; it never reduces a command —
 * command interpretation is Producer's wire layer, and its operational replay of these sixty
 * vectors arrives with the `kumwe/producer` adoption pin. What the App can and must prove today is
 * storage fidelity: every vector's `initial` document, and the `expect.document` of every
 * successful vector, is admitted by exactly the compiled pinned validator the host boundary runs,
 * so no state a command replay starts from or arrives at could be a document this host refuses to
 * store. Failing vectors publish a closed `errorCode` instead of a document, and each vector
 * carries exactly one of the two expectations.
 *
 * @since  2.0.0
 */
#[CoversClass(SchemaPropertyValidator::class)]
#[CoversClass(StudioContractSchemas::class)]
final class StudioCommandVectorStorageFidelityTest extends TestCase
{
    /**
     * The artifact kinds a command vector may carry as stored document state.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array ARTIFACT_KINDS = ['blueprint', 'content-model', 'entry'];

    /**
     * A vector's initial document is admitted by the pinned validator for its artifact kind.
     *
     * @param   string  $file  Vector document path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('commandVectors')]
    public function testTheInitialDocumentIsAdmittedByThePinnedValidator(string $file): void
    {
        $vector = self::decode($file);
        self::assertSame('command-vector', $vector->kind ?? null, 'Every vector declares its envelope kind.');

        $initial = $vector->initial ?? null;
        self::assertInstanceOf(stdClass::class, $initial, 'Every vector starts from a stored document.');
        self::assertAdmitted($initial, sprintf('the initial document of %s', basename($file)));
    }

    /**
     * A vector expects exactly one outcome: an admitted document, or a closed error code.
     *
     * @param   string  $file  Vector document path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('commandVectors')]
    public function testTheExpectationIsAnAdmittedDocumentOrAClosedErrorCode(string $file): void
    {
        $vector = self::decode($file);
        $expect = $vector->expect ?? null;
        self::assertInstanceOf(stdClass::class, $expect, 'Every vector publishes its expectation.');

        $members = array_keys(get_object_vars($expect));
        sort($members, SORT_STRING);
        if ($members === ['document']) {
            self::assertAdmitted($expect->document, sprintf('the expected document of %s', basename($file)));

            return;
        }
        self::assertSame(
            ['errorCode'],
            $members,
            sprintf('%s must expect exactly a document or exactly an error code.', basename($file)),
        );
        self::assertIsString($expect->errorCode);
        self::assertNotSame('', $expect->errorCode, 'A failing vector names its closed error code.');
    }

    /**
     * The replayed set is exactly the manifest's `command-vectors` group, and both outcomes occur.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheReplaySetIsExactlyTheManifestGroup(): void
    {
        $documents = 0;
        $errors = 0;
        $total = 0;
        foreach (self::commandVectors() as [$file]) {
            $total++;
            if (isset(self::decode($file)->expect->document)) {
                $documents++;
                continue;
            }
            $errors++;
        }

        self::assertSame(
            self::manifestGroupSize('command-vectors'),
            $total,
            'Every digest-verified command vector must be replayed.',
        );
        self::assertGreaterThan(0, $documents, 'The pinned corpus must publish successful outcomes.');
        self::assertGreaterThan(0, $errors, 'The pinned corpus must publish refused outcomes.');
    }

    /**
     * Every vendored command vector document.
     *
     * @return  iterable<string, array{0: string}>  Vector paths keyed by file name.
     *
     * @since   2.0.0
     */
    public static function commandVectors(): iterable
    {
        $files = glob(self::corpusRoot() . '/testkit/vectors/command/*.json');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'The vendored command-vector directory must not be empty.');
        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }

    /**
     * Assert one document is a storable artifact kind admitted by its compiled pinned validator.
     *
     * @param   mixed   $document  Candidate stored document state.
     * @param   string  $subject   Human-readable coordinate for the failure message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertAdmitted(mixed $document, string $subject): void
    {
        self::assertInstanceOf(stdClass::class, $document);
        $kind = $document->kind ?? null;
        self::assertIsString($kind);
        self::assertContains($kind, self::ARTIFACT_KINDS, sprintf('%s must be a storable artifact.', $subject));

        $validator = StudioContractSchemas::fromVendoredCorpus()->validator($kind);
        $admitted = $validator->validate($document);
        $diagnostics = $validator->diagnostics() ?? [];
        $first = $diagnostics[0] ?? null;
        self::assertTrue($admitted, sprintf(
            'This host must admit %s for storage; first diagnostic: %s %s.',
            $subject,
            $first->instancePath ?? '(none)',
            $first->keyword ?? '',
        ));
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
