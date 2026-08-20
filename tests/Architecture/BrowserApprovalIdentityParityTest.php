<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds the browser matrix to a single definition that both languages read.
 *
 * The maker-checker journey needs an approval identity per project, because TOTP enrollment is a
 * once-per-account operation and the breadth lane runs several projects in one invocation against one
 * database. When the configuration named its projects in TypeScript and the seeder named them again in
 * PHP, two projects silently shared one account: the second met a refusal that renders a notice and no
 * provisioning element, so the journey waited on something that would never appear and reported nothing
 * but a ninety-second timeout.
 *
 * The first attempt to guard that compared the two lists by pattern-matching the source, which could
 * miss the drift it existed to catch — a project declared without a spec filter runs every journey by
 * default and matched no pattern at all. Both sides now derive from `tests/Browser/projects.json`, which
 * makes the mismatch unrepresentable rather than merely detected. What remains worth asserting is that
 * they still do, and that a project added to the manifest cannot arrive without the emulation options
 * that decide what it actually renders.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class BrowserApprovalIdentityParityTest extends TestCase
{
    /**
     * Read the browser matrix manifest.
     *
     * @return  array{retries: int, projects: list<array{name: string, specs: string}>}  The manifest.
     *
     * @since   2.0.0
     */
    private function matrix(): array
    {
        $path = dirname(__DIR__) . '/Browser/projects.json';
        self::assertFileExists($path, 'The browser matrix manifest is missing.');
        /** @var array{retries: int, projects: list<array{name: string, specs: string}>} $matrix */
        $matrix = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $matrix;
    }

    /**
     * Both the configuration and the seeder read the manifest rather than restating it.
     *
     * A hard-coded project list in either file is the exact shape of the original defect, so its absence
     * is what this asserts — not that two restatements happen to agree today.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheConfigurationAndTheSeederBothDeriveFromTheManifest(): void
    {
        $root = dirname(__DIR__, 2);
        $configuration = (string) file_get_contents($root . '/playwright.config.ts');
        $seeder = (string) file_get_contents($root . '/tests/Support/prepare-browser-contribution.php');

        self::assertStringContainsString('tests/Browser/projects.json', $configuration);
        self::assertStringContainsString('Browser/projects.json', $seeder);

        foreach ($this->matrix()['projects'] as $project) {
            self::assertStringNotContainsString(
                sprintf("name: '%s'", $project['name']),
                $configuration,
                'The configuration restates a project the manifest already defines; it must map over the '
                . 'manifest so a project cannot exist in one place and be missing from the other.',
            );
            self::assertStringNotContainsString(
                sprintf("'%s'", $project['name']),
                $seeder,
                'The seeder names a project literally; it must take its projects from the manifest.',
            );
        }
    }

    /**
     * Every project the manifest declares carries emulation options of its own.
     *
     * Deriving projects from the manifest introduced one way to add a silently wrong project: a name the
     * options table does not know falls back to an empty object, which is a desktop render with no device
     * emulation. A mobile project in that state would pass its accessibility assertions against the wrong
     * viewport, which is worse than failing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryDeclaredProjectHasEmulationOptions(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 2) . '/playwright.config.ts');

        foreach ($this->matrix()['projects'] as $project) {
            self::assertMatchesRegularExpression(
                sprintf("/'%s':\s*\{/", preg_quote($project['name'], '/')),
                $configuration,
                sprintf(
                    'Project %s has no emulation options, so it would run with none at all.',
                    $project['name'],
                ),
            );
        }
    }

    /**
     * The seeder provisions an identity for every attempt the manifest budgets.
     *
     * An enrollment cannot be repeated, so a retry that reuses the previous attempt's account fails the
     * same way the shared-project defect did. Binding both to one number is what keeps a raised retry
     * budget from quietly outrunning the fixtures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSeederCoversEveryBudgetedAttempt(): void
    {
        $seeder = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Support/prepare-browser-contribution.php');

        self::assertStringContainsString(
            "range(0, \$matrix['retries'])",
            $seeder,
            'The attempts the seeder provisions must come from the manifest retry budget, not a literal.',
        );
        self::assertGreaterThanOrEqual(1, $this->matrix()['retries']);
    }
}
