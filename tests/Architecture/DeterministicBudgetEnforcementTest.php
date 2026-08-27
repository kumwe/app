<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins each of the capacity contract's eight deterministic per-change budgets to a live enforcement point.
 *
 * The contract lists eight budgets every pull request is held to, and a budget whose enforcement quietly
 * disappears keeps failing nobody while the document still claims it. Each case here names the file that
 * enforces one budget and the marker that is the enforcement — an assertion message, a declared ceiling,
 * a workflow guard — so deleting or defanging the enforcement fails this suite by the budget's own name.
 * The markers are deliberately the *load-bearing* strings: not a comment about the budget, but the text
 * whose absence means the check no longer runs.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class DeterministicBudgetEnforcementTest extends TestCase
{
    /**
     * Every declared budget, its enforcement file and the marker that is the enforcement.
     *
     * @return  array<string, array{string, string}>  Enforcement file and marker, keyed by budget.
     *
     * @since   2.0.0
     */
    public static function budgets(): array
    {
        return [
            'query count may not grow per line, item or result' => [
                'tests/Integration/BusinessRecord/AggregateDocumentIntegrationTest.php',
                'A thousand lines must be written in bounded batches',
            ],
            'no new N+1 query slope' => [
                'tests/Integration/BusinessSurface/GeneratedBusinessQueryBudgetIntegrationTest.php',
                'must not issue one query per definition',
            ],
            'bounded memory and encoded payload size at 1000 lines' => [
                'tests/Integration/BusinessRecord/DocumentCommitInstrumentationIntegrationTest.php',
                'MAXIMUM_MEMORY_DELTA_BYTES',
            ],
            'indexed cursor pagination rather than deep offset' => [
                'tests/Integration/BusinessRecord/BusinessRecordLargeDatasetIntegrationTest.php',
                'A keyset cursor must always advance',
            ],
            'no full scan, sort or spill on a declared hot plan' => [
                'tests/Integration/Performance/HotPlanRegressionIntegrationTest.php',
                'with no indexed access path',
            ],
            'migration plan runtime budget' => [
                '.github/workflows/development-compose.yml',
                'declared runtime budget',
            ],
            'statement and lock-duration budget for changed transactional paths' => [
                'tests/Integration/BusinessRecord/BusinessNumberSequenceHotPathIntegrationTest.php',
                'assertLessThanOrEqual',
            ],
            'artifact and image size regression budget' => [
                'tools/verify-artifact-budget.sh',
                'maximum_bytes',
            ],
        ];
    }

    /**
     * The budget's enforcement file exists and still carries its load-bearing marker.
     *
     * @param   string  $path    Repository-relative enforcement file.
     * @param   string  $marker  Text whose absence means the enforcement no longer runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('budgets')]
    public function testTheBudgetHasALiveEnforcementPoint(string $path, string $marker): void
    {
        $absolute = dirname(__DIR__, 2) . '/' . $path;
        self::assertFileExists($absolute, sprintf('The enforcement point %s is gone.', $path));
        self::assertStringContainsString(
            $marker,
            (string) file_get_contents($absolute),
            sprintf('%s no longer carries the enforcement this budget rests on.', $path),
        );
    }

    /**
     * The size ceilings and the hot-plan registry both still declare something to enforce.
     *
     * An empty registry is the quiet way an enforcement dies while its file survives, so the two
     * declaration documents are held to declaring at least one entry each.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDeclarationDocumentsDeclareSomething(): void
    {
        $root = dirname(__DIR__, 2);
        $budget = json_decode((string) file_get_contents($root . '/docs/quality/artifact-budget.json'), true);
        self::assertIsArray($budget);
        self::assertIsArray($budget['artifacts'] ?? null);
        self::assertNotSame([], $budget['artifacts'], 'The artifact budget declares no ceilings.');
        foreach ($budget['artifacts'] as $name => $artifact) {
            self::assertIsArray($artifact);
            self::assertIsInt(
                $artifact['maximum_bytes'] ?? null,
                sprintf('Artifact %s declares no byte ceiling.', (string) $name),
            );
        }

        $plans = json_decode((string) file_get_contents($root . '/docs/quality/hot-plans.json'), true);
        self::assertIsArray($plans);
        self::assertIsArray($plans['plans'] ?? null);
        self::assertNotSame([], $plans['plans'], 'The hot-plan registry declares no plans.');

        $workflow = (string) file_get_contents($root . '/.github/workflows/security.yml');
        self::assertStringContainsString(
            'verify-artifact-budget.sh production_runtime_image',
            $workflow,
            'The runtime image is no longer held to its size budget where it is built.',
        );
        self::assertStringContainsString(
            'verify-artifact-budget.sh production_web_image',
            $workflow,
            'The web image is no longer held to its size budget where it is built.',
        );
        self::assertStringContainsString(
            'verify-artifact-budget.sh release_archive',
            (string) file_get_contents($root . '/.github/workflows/release.yml'),
            'The release archive is no longer held to its size budget where it is built.',
        );
    }
}
