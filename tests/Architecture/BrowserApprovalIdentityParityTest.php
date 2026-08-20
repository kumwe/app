<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds the seeded maker-checker identities to the Playwright projects that actually use them.
 *
 * TOTP enrollment is a once-per-account operation, and the nightly runs several projects in one
 * invocation against one database that is seeded before the run and never reset. Two projects sharing
 * one approval account therefore leave the second unable to enroll — and because the refused enrollment
 * renders a notice rather than the panel the journey waits for, it surfaces as nothing but a
 * ninety-second timeout with no assertion attached. That is expensive to diagnose and trivial to
 * reintroduce: the seeder names its projects in PHP, the configuration names them in TypeScript, and
 * nothing but this test connects the two.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class BrowserApprovalIdentityParityTest extends TestCase
{
    /**
     * Every project that runs the portal journeys has an approval identity of its own.
     *
     * The right-to-left projects are excluded by the same signal the configuration uses: `testMatch`
     * confines them to the right-to-left spec, which never enrolls an authenticator, while `testIgnore`
     * marks the projects that run everything else — including the maker-checker journey.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryProjectRunningThePortalJourneysHasItsOwnApprovalIdentity(): void
    {
        $root = dirname(__DIR__, 2);
        $configuration = (string) file_get_contents($root . '/playwright.config.ts');
        $seeder = (string) file_get_contents($root . '/tests/Support/prepare-browser-contribution.php');

        self::assertSame(
            1,
            preg_match_all(
                "/name: '([a-z0-9-]+)',\s*\n\s*(testIgnore|testMatch): rightToLeftSpec/",
                $configuration,
                $projects,
                PREG_SET_ORDER,
            ) > 0 ? 1 : 0,
            'No Playwright projects could be read from playwright.config.ts.',
        );

        $portalProjects = [];
        foreach ($projects as $project) {
            if ($project[2] === 'testIgnore') {
                $portalProjects[] = $project[1];
            }
        }
        sort($portalProjects, SORT_STRING);

        self::assertSame(
            1,
            preg_match("/foreach \(\[([^\]]+)\] as \\\$project\) \{/", $seeder, $seeded),
            'The approval-identity project list could not be read from the seeder.',
        );
        $seededProjects = array_map(
            static fn (string $entry): string => trim(trim($entry), "'"),
            explode(',', trim($seeded[1])),
        );
        $seededProjects = array_values(array_filter(
            $seededProjects,
            static fn (string $entry): bool => $entry !== '',
        ));
        sort($seededProjects, SORT_STRING);

        self::assertSame(
            $portalProjects,
            $seededProjects,
            'Every Playwright project that runs the portal journeys needs its own seeded maker and '
            . 'approver, because TOTP enrollment cannot be repeated on one account.',
        );
    }
}
