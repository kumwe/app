<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use Kumwe\App\Extension\Contribution\ExtensionContributionSummary;
use Kumwe\App\Extension\Domain\ExtensionManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the contribution summary maps each declared contribution to where it surfaces.
 *
 * The projection is what the Extensions screen and the install commands both present, so what is
 * pinned here is the operator-facing contract: routable contributions resolve to the exact paths
 * the route registries mount them on, background work names where its output goes, themes report
 * their activation state, and a disabled package still lists everything it would contribute. The
 * shipped example manifests are used as fixtures because they are the packages the demonstration
 * installs — the summary these tests pin is the one the demo user reads.
 *
 * @since  2.0.0
 */
#[CoversClass(ExtensionContributionSummary::class)]
final class ExtensionContributionSummaryTest extends TestCase
{
    /**
     * The announcements component's screen resolves to its mounted administrator URL.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorScreenIsLinkedAtItsMountedPath(): void
    {
        $summary = ExtensionContributionSummary::project($this->manifest('announcements'), true);
        $administrator = $this->group($summary, 'administrator');

        $screen = $administrator['entries'][0];
        self::assertSame('administrator screen', $screen['noun']);
        self::assertSame('Announcements', $screen['label']);
        self::assertSame('/administrator/extensions/kumwe/announcements-example', $screen['href']);
        self::assertTrue($screen['active']);
        self::assertStringContainsString('administrator menu', $screen['detail']);
        self::assertStringContainsString('kumwe.announcements-example.manage', $screen['detail']);
    }

    /**
     * Declared capabilities point the operator at the granting screen, since nobody holds them yet.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCapabilitiesPointAtTheAccessScreen(): void
    {
        $summary = ExtensionContributionSummary::project($this->manifest('announcements'), true);
        $capabilities = $this->group($summary, 'capabilities');

        self::assertSame('kumwe.announcements-example.manage', $capabilities['entries'][0]['label']);
        self::assertSame('/administrator/access', $capabilities['entries'][0]['href']);
        self::assertStringContainsString('granted to no role automatically', $capabilities['entries'][0]['detail']);
    }

    /**
     * Contributed record types link to their generated Business workspaces screens.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBusinessDefinitionsLinkToTheirWorkspaces(): void
    {
        $summary = ExtensionContributionSummary::project($this->manifest('announcements'), true);
        $content = $this->group($summary, 'content');

        $hrefs = array_column($content['entries'], 'href', 'label');
        self::assertSame(
            '/administrator/business/kumwe.announcements-example.announcement',
            $hrefs['Announcements'],
        );
        self::assertSame(
            '/administrator/business/kumwe.announcements-example.category',
            $hrefs['Announcement categories'],
        );

        $nouns = array_column($content['entries'], 'noun', 'label');
        self::assertSame('field type', $nouns['Announcement severity']);
    }

    /**
     * The asset-inspection portal view and reports resolve to their portal and report URLs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalViewsAndReportsAreLinked(): void
    {
        $summary = ExtensionContributionSummary::project($this->manifest('asset-inspection'), true);
        $portal = $this->group($summary, 'portal');

        $hrefs = array_column($portal['entries'], 'href', 'label');
        self::assertSame('/portal/extensions/kumwe/asset-inspection-example', $hrefs['Inspection status']);
        self::assertSame(
            '/portal/reports/kumwe.asset-inspection-example.inspection-summary',
            $hrefs['Asset inspection example summary'],
        );

        $administrator = $this->group($summary, 'administrator');
        $administratorHrefs = array_column($administrator['entries'], 'href', 'label');
        self::assertSame(
            '/administrator/reports/kumwe.asset-inspection-example.inspection-summary',
            $administratorHrefs['Asset inspection example summary'],
        );
    }

    /**
     * Background contributions say where their work runs and where the operator can watch it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAutomationEntriesNameWhereTheirOutputGoes(): void
    {
        $summary = ExtensionContributionSummary::project($this->manifest('asset-inspection'), true);
        $automation = $this->group($summary, 'automation');

        $byLabel = [];
        foreach ($automation['entries'] as $entry) {
            $byLabel[$entry['label']] = $entry;
        }
        $schedule = $byLabel['kumwe.asset-inspection-example.review-overdue-daily'];
        self::assertSame('/administrator/automation', $schedule['href']);
        self::assertStringContainsString('15 2 * * *', $schedule['detail']);
        self::assertStringContainsString('Automation screen', $schedule['detail']);

        $consumer = $byLabel['kumwe.asset-inspection-example.inspection-mutation-indexer'];
        self::assertNull($consumer['href']);
        self::assertStringContainsString('kumwe.asset-inspection-example.integration', $consumer['detail']);

        $listener = $byLabel['kumwe.asset-inspection-example.inspection-mutation-validator'];
        self::assertNull($listener['href']);
        self::assertStringContainsString('core.business_record.mutated', $listener['detail']);
    }

    /**
     * A schema-one plugin's consumed event is listed as a background listener with no screen.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLegacyEventListenerIsNamedWithItsEvent(): void
    {
        $summary = ExtensionContributionSummary::project($this->manifest('audit-listener'), true);
        $automation = $this->group($summary, 'automation');

        self::assertSame('event listener', $automation['entries'][0]['noun']);
        self::assertSame('onKumweExtensionAfterActivate', $automation['entries'][0]['label']);
        self::assertNull($automation['entries'][0]['href']);
        self::assertStringContainsString('no screen of its own', $automation['entries'][0]['detail']);
    }

    /**
     * A selectable theme explains which surface it would dress and how to activate it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSelectableThemeExplainsItsActivationStep(): void
    {
        $summary = ExtensionContributionSummary::project(
            $this->manifest('horizon-theme'),
            false,
            [],
            ['site'],
        );
        $theme = $this->group($summary, 'theme');

        self::assertSame('Public site theme', $theme['entries'][0]['label']);
        self::assertNull($theme['entries'][0]['href']);
        self::assertFalse($theme['entries'][0]['active']);
        self::assertStringContainsString('Use for site', $theme['entries'][0]['detail']);
    }

    /**
     * A theme activated on the site surface links to the public site it now dresses.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testActivatedThemeLinksToTheSiteItDresses(): void
    {
        $summary = ExtensionContributionSummary::project(
            $this->manifest('horizon-theme'),
            false,
            ['site'],
            ['site'],
        );
        $theme = $this->group($summary, 'theme');

        self::assertSame('/', $theme['entries'][0]['href']);
        self::assertTrue($theme['entries'][0]['active']);
        self::assertStringContainsString('Dressing the public site', $theme['entries'][0]['detail']);
    }

    /**
     * A disabled extension still lists every contribution, each flagged inactive.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDisabledExtensionStillListsItsContributionsAsInactive(): void
    {
        $summary = ExtensionContributionSummary::project($this->manifest('announcements'), false);

        self::assertNotSame([], $summary);
        foreach ($summary as $group) {
            foreach ($group['entries'] as $entry) {
                self::assertFalse($entry['active'], $group['kind'] . ': ' . $entry['label']);
            }
        }
        $administrator = $this->group($summary, 'administrator');
        self::assertSame(
            '/administrator/extensions/kumwe/announcements-example',
            $administrator['entries'][0]['href'],
        );
    }

    /**
     * Console lines mirror the screen: active entries read "adds", inactive ones stay conditional.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLinesReportEachEntryFactually(): void
    {
        $active = ExtensionContributionSummary::lines(
            ExtensionContributionSummary::project($this->manifest('announcements'), true),
        );
        self::assertContains(
            'adds administrator screen Announcements at /administrator/extensions/kumwe/announcements-example'
            . ' — Listed in the administrator menu under "Announcements"; requires the'
            . ' kumwe.announcements-example.manage capability.',
            $active,
        );

        $inactive = ExtensionContributionSummary::lines(
            ExtensionContributionSummary::project($this->manifest('announcements'), false),
        );
        self::assertNotSame([], $inactive);
        foreach ($inactive as $line) {
            self::assertStringStartsWith('would add (once active) ', $line);
        }
    }

    /**
     * A registry row without a projected summary yields no lines instead of an error.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRowsWithoutASummaryYieldNoLines(): void
    {
        self::assertSame([], ExtensionContributionSummary::linesForRow([
            'identifier' => 'kumwe/announcements-example',
            'status' => 'active',
        ]));
    }

    /**
     * Read one shipped example's manifest, the same declaration the demo installer packages.
     *
     * @param   string  $example  Example directory name under `examples/extensions`.
     *
     * @return  ExtensionManifest  The parsed manifest.
     *
     * @since   2.0.0
     */
    private function manifest(string $example): ExtensionManifest
    {
        $json = file_get_contents(sprintf(
            '%s/examples/extensions/%s/kumwe.json',
            dirname(__DIR__, 4),
            $example,
        ));
        self::assertIsString($json);

        return ExtensionManifest::fromJson($json);
    }

    /**
     * Find one kind's group in a projected summary, failing when the kind is absent.
     *
     * @param   list<array{kind: string, heading: string, entries: list<array{
     *              noun: string, label: string, href: ?string, detail: string, active: bool
     *          }>}>    $summary  Projected summary under test.
     * @param   string  $kind     Machine kind of the wanted group.
     *
     * @return  array{kind: string, heading: string, entries: list<array{
     *              noun: string, label: string, href: ?string, detail: string, active: bool
     *          }>}  The matching group.
     *
     * @since   2.0.0
     */
    private function group(array $summary, string $kind): array
    {
        foreach ($summary as $group) {
            if ($group['kind'] === $kind) {
                self::assertNotSame([], $group['entries']);

                return $group;
            }
        }
        self::fail(sprintf('The %s contribution group is absent.', $kind));
    }
}
