<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Support;

use Kumwe\App\Tests\Support\BrowserProjectManifest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Holds the PHP manifest reader to the corpus its JavaScript twin answers.
 *
 * The manifest is the single definition of the browser matrix, and its worth rests entirely on both
 * consumers reading the same documents the same way. They did not: Playwright ran every journey for any
 * `specs` value that was not `right-to-left` while this side provisioned identities only for exactly
 * `all`, so one misspelled word ran the maker-checker journey on a project with no approval identity —
 * reintroducing the once-per-account TOTP refusal the manifest exists to prevent, with every guard
 * still green.
 *
 * The first version of this guard kept its own list and `tools/verify-browser-manifest.mjs` kept
 * another, which left the two free to disagree in precisely the way the manifest was meant to stop:
 * `{"retries":1.0}` was accepted there, because `Number.isInteger(1)` is true, and refused here,
 * because `json_decode` yields a float. Neither list could see it. Both halves now run
 * `tests/Browser/manifest-cases.json`, which carries raw sources so a lexical form one language
 * normalises and the other does not survives into the case, and neither half owns a case of its own.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class BrowserProjectManifestTest extends TestCase
{
    /**
     * Every document the corpus refuses, keyed by what is wrong with it.
     *
     * @return  array<string, array{string}>  Raw manifest sources keyed by their corpus label.
     *
     * @since   2.0.0
     */
    public static function refusedManifests(): array
    {
        $refused = [];
        foreach (self::corpus() as $case) {
            if ($case['outcome'] === 'refused') {
                $refused[$case['label']] = [$case['source']];
            }
        }

        return $refused;
    }

    /**
     * Every document the corpus accepts, with the one reading both consumers must produce.
     *
     * @return  array<string, array{string, array{retries: int, projects: list<array{name: string, specs: string}>}}>
     *          Raw manifest sources and their required reading, keyed by their corpus label.
     *
     * @since   2.0.0
     */
    public static function acceptedManifests(): array
    {
        $accepted = [];
        foreach (self::corpus() as $case) {
            if ($case['outcome'] === 'accepted') {
                /** @var array{retries: int, projects: list<array{name: string, specs: string}>} $expected */
                $expected = $case['expected'];
                $accepted[$case['label']] = [$case['source'], $expected];
            }
        }

        return $accepted;
    }

    /**
     * A document either consumer would read differently is refused rather than interpreted.
     *
     * @param   string  $manifest  Raw manifest source the corpus refuses.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('refusedManifests')]
    public function testAManifestTheTwoConsumersWouldReadDifferentlyIsRefused(string $manifest): void
    {
        $this->expectException(RuntimeException::class);

        BrowserProjectManifest::parse($manifest, 'fixture');
    }

    /**
     * An accepted document is read exactly as the corpus states, not merely accepted.
     *
     * Agreeing to accept a document while reading it differently is the same defect as disagreeing
     * about whether to accept it: `1.0`, `1e0` and `-0` are the values the two languages are most
     * likely to part company on, and the reading is what the seeder and Playwright then act on.
     *
     * @param   string                                                            $manifest  Raw source.
     * @param   array{retries: int, projects: list<array{name: string, specs: string}>}  $expected  Reading.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('acceptedManifests')]
    public function testAnAcceptedManifestIsReadExactlyAsTheCorpusStates(string $manifest, array $expected): void
    {
        self::assertSame($expected, BrowserProjectManifest::parse($manifest, 'fixture'));
    }

    /**
     * The corpus holds cases of both outcomes, so neither half of the rule can go untested.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSharedCorpusExercisesBothOutcomes(): void
    {
        self::assertNotSame([], self::refusedManifests(), 'The corpus refuses nothing.');
        self::assertNotSame([], self::acceptedManifests(), 'The corpus accepts nothing.');
    }

    /**
     * The committed manifest is readable, and every project it declares carries a known scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedManifestIsValidAndEveryScopeIsKnown(): void
    {
        $matrix = BrowserProjectManifest::read(dirname(__DIR__, 3) . '/tests/Browser/projects.json');

        self::assertGreaterThanOrEqual(0, $matrix['retries']);
        self::assertLessThanOrEqual(BrowserProjectManifest::MAX_RETRIES, $matrix['retries']);
        self::assertNotSame([], $matrix['projects']);
        foreach ($matrix['projects'] as $project) {
            self::assertContains($project['specs'], BrowserProjectManifest::SPEC_SCOPES);
        }
    }

    /**
     * Read the shared corpus, refusing one that could let a case pass without being run.
     *
     * A case with an unrecognised outcome, or an accepted case with no stated reading, would otherwise
     * be skipped in silence — and a guard that silently runs nothing is worse than none, because it
     * reports success.
     *
     * @return  list<array{label: string, source: string, outcome: string, expected: array<string, mixed>|null}>
     *          Every case the corpus declares.
     *
     * @throws  RuntimeException  When the corpus is not one both halves can be held to.
     *
     * @since   2.0.0
     */
    private static function corpus(): array
    {
        $path = dirname(__DIR__, 3) . '/tests/Browser/manifest-cases.json';
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new RuntimeException(sprintf('%s cannot be read.', $path));
        }
        /** @var mixed $document */
        $document = json_decode($source, true);
        if (!is_array($document) || !isset($document['cases']) || !is_array($document['cases'])) {
            throw new RuntimeException(sprintf('%s needs a "cases" array.', $path));
        }

        $cases = [];
        $labels = [];
        /** @var mixed $case */
        foreach ($document['cases'] as $case) {
            if (!is_array($case)) {
                throw new RuntimeException(sprintf('%s holds a case that is not an object.', $path));
            }
            $label = $case['label'] ?? null;
            if (!is_string($label) || $label === '') {
                throw new RuntimeException(sprintf('%s holds a case with no label.', $path));
            }
            if (in_array($label, $labels, true)) {
                throw new RuntimeException(sprintf('%s declares "%s" twice.', $path, $label));
            }
            $labels[] = $label;
            $manifest = $case['source'] ?? null;
            if (!is_string($manifest)) {
                throw new RuntimeException(sprintf('Case "%s" needs its manifest as a raw source string.', $label));
            }
            $outcome = $case['outcome'] ?? null;
            if ($outcome !== 'refused' && $outcome !== 'accepted') {
                throw new RuntimeException(sprintf('Case "%s" needs an outcome of refused or accepted.', $label));
            }
            /** @var mixed $expected */
            $expected = $case['expected'] ?? null;
            if ($outcome === 'accepted' && !is_array($expected)) {
                throw new RuntimeException(sprintf('Accepted case "%s" must state the reading it requires.', $label));
            }
            if ($outcome === 'refused' && $expected !== null) {
                throw new RuntimeException(sprintf('Refused case "%s" cannot state a reading.', $label));
            }
            /** @var array<string, mixed>|null $expected */
            $cases[] = ['label' => $label, 'source' => $manifest, 'outcome' => $outcome, 'expected' => $expected];
        }
        if ($cases === []) {
            throw new RuntimeException(sprintf('%s declares no cases.', $path));
        }

        return $cases;
    }
}
