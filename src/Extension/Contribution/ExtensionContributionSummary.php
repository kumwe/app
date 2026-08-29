<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Manifest\ExtensionType;
use Kumwe\App\Portal\Contribution\PortalRouteRegistry;

/**
 * Projects one installed extension's declared contributions into the map an operator can follow.
 *
 * The registry knows what a package declared and the route registries know where those declarations
 * mount, but nothing showed an operator the two together — after installing an extension, nothing
 * said where its screens, listeners, or records actually surface in the product. This projection
 * closes that gap from the manifest alone, which is the same declaration the compiled runtime map is
 * built from and held to, so the summary never invents an inventory of its own. Because the manifest
 * exists whether or not the extension is active, an installed-but-disabled package still lists what
 * it would contribute, each entry flagged inactive instead of silently missing.
 *
 * @phpstan-type SummaryEntry array{
 *             noun: string,
 *             label: string,
 *             href: ?string,
 *             detail: string,
 *             active: bool
 *         }
 * @phpstan-type SummaryGroup array{kind: string, heading: string, entries: list<SummaryEntry>}
 *
 * @since  2.0.0
 */
final readonly class ExtensionContributionSummary
{
    /**
     * Group a manifest's contributions by kind, each entry naming where its effect shows up.
     *
     * Routable contributions carry the real mounted URL, composed by the same rules the route
     * registries mount them with; non-routable ones say in prose where their output lands. The
     * `active` flag on every entry follows the extension's registry status — and, for a theme, the
     * surface activation records — so a disabled package's declarations render as promises rather
     * than links to pages that would refuse the request.
     *
     * @param   ExtensionManifest  $manifest           Installed release manifest the summary is read from.
     * @param   bool               $active             Whether the registry currently lists the extension
     *          as `active`.
     * @param   list<string>       $themeSurfaces      Surfaces a template is activated on: `site`,
     *          `administrator`, or `site:<identifier>` for one site.
     * @param   list<string>       $dressableSurfaces  Surfaces the deployed template package ships
     *          templates for; ignored for non-template extensions.
     *
     * @return  list<SummaryGroup>  Non-empty groups in presentation order; empty when the package
     *          declares nothing an operator could visit or observe.
     *
     * @since   2.0.0
     */
    public static function project(
        ExtensionManifest $manifest,
        bool $active,
        array $themeSurfaces = [],
        array $dressableSurfaces = [],
    ): array {
        $contributions = $manifest->contributions();
        $host = new CanonicalManifestInterpreter($contributions);
        $owner = $contributions->owner;
        $groups = [
            self::group('administrator', 'Administrator screens', self::administratorEntries(
                $contributions,
                $host,
                $owner,
                $active,
            )),
            self::group('portal', 'Portal views', self::portalEntries($contributions, $host, $owner, $active)),
            self::group('automation', 'Automation and listeners', self::automationEntries(
                $contributions,
                $host,
                $active,
            )),
            self::group('theme', 'Theme surfaces', self::themeEntries(
                $manifest,
                $themeSurfaces,
                $dressableSurfaces,
            )),
            self::group('content', 'Content and definitions', self::contentEntries($host, $active)),
            self::group('capabilities', 'Capabilities to grant', self::capabilityEntries($host, $active)),
        ];

        return array_values(array_filter($groups, static fn (array $group): bool => $group['entries'] !== []));
    }

    /**
     * Flatten a projected summary into the factual lines a console command prints.
     *
     * @param   list<SummaryGroup>  $summary  Groups produced by `project()`.
     *
     * @return  list<string>  One line per entry, mirroring the Extensions screen's content.
     *
     * @since   2.0.0
     */
    public static function lines(array $summary): array
    {
        $lines = [];
        foreach ($summary as $group) {
            foreach ($group['entries'] as $entry) {
                $lines[] = sprintf(
                    '%s %s %s%s — %s',
                    $entry['active'] ? 'adds' : 'would add (once active)',
                    $entry['noun'],
                    $entry['label'],
                    $entry['href'] === null ? '' : ' at ' . $entry['href'],
                    $entry['detail'],
                );
            }
        }

        return $lines;
    }

    /**
     * Flatten the projected summary carried on one `ExtensionManager::installed()` row.
     *
     * Console commands hold the loosely typed registry row rather than the projection, so this is
     * the boundary that reads the `contribution_summary` key back out of it; a row without one —
     * from an older caller or a stub — yields no lines rather than an error.
     *
     * @param   array<string, mixed>  $row  Registry row as the manager lists it.
     *
     * @return  list<string>  The same lines `lines()` produces; empty when the row carries no summary.
     *
     * @since   2.0.0
     */
    public static function linesForRow(array $row): array
    {
        $summary = $row['contribution_summary'] ?? [];
        if (!is_array($summary)) {
            return [];
        }
        /** @var list<SummaryGroup> $summary */
        return self::lines($summary);
    }

    /**
     * Assemble one kind's group envelope.
     *
     * @param   string              $kind     Machine name of the contribution kind.
     * @param   string              $heading  Heading the Extensions screen renders for the group.
     * @param   list<SummaryEntry>  $entries  Entries belonging to the kind; may be empty.
     *
     * @return  SummaryGroup  The group as the screen and console consume it.
     *
     * @since   2.0.0
     */
    private static function group(string $kind, string $heading, array $entries): array
    {
        return ['kind' => $kind, 'heading' => $heading, 'entries' => $entries];
    }

    /**
     * Describe the administrator pages, actions, and reports the package mounts.
     *
     * Each `GET` route is presented under its navigation label where one points at it, linked at the
     * exact path `AdministratorRouteRegistry` mounts it on; mutation-only routes are named with their
     * endpoint instead of linked. Administrator-visible reports are included here because that is
     * where an operator opens them.
     *
     * @param   ManifestContributions         $contributions  Canonical SDK declaration graph.
     * @param   CanonicalManifestInterpreter  $host           App-owned semantic interpretation.
     * @param   ContributionOwner             $owner          Package owning every declaration in the set.
     * @param   bool                          $active         Whether the extension is currently active.
     *
     * @return  list<SummaryEntry>  Administrator-facing entries; empty when none are declared.
     *
     * @since   2.0.0
     */
    private static function administratorEntries(
        ManifestContributions $contributions,
        CanonicalManifestInterpreter $host,
        ContributionOwner $owner,
        bool $active,
    ): array {
        $workspaceLabels = [];
        foreach ($contributions->administratorWorkspaces() as $workspace) {
            $workspaceLabels[$workspace->id] = $workspace->label;
        }
        $navigation = [];
        foreach ($contributions->administratorNavigation() as $item) {
            $navigation[$item->path . '|' . $item->capability] = $item;
        }
        $entries = [];
        foreach ($contributions->administratorRoutes() as $route) {
            $mounted = AdministratorRouteRegistry::routePath($owner, $route);
            $item = $navigation[$route->path . '|' . $route->capability] ?? null;
            $workspace = $item === null ? null : ($workspaceLabels[$item->workspace] ?? null);
            if (in_array('GET', $route->methods, true)) {
                $entries[] = [
                    'noun' => 'administrator screen',
                    'label' => $item->label ?? $route->name,
                    'href' => str_contains($mounted, '{') ? null : $mounted,
                    'detail' => $item === null
                        ? sprintf('Requires the %s capability.', $route->capability)
                        : sprintf(
                            'Listed in the administrator menu under "%s"; requires the %s capability.',
                            $workspace ?? $item->label,
                            $route->capability,
                        ),
                    'active' => $active,
                ];
                continue;
            }
            $entries[] = [
                'noun' => 'administrator action',
                'label' => $route->name,
                'href' => null,
                'detail' => sprintf(
                    '%s endpoint at %s; requires the %s capability.',
                    implode('/', array_filter($route->methods, 'is_string')),
                    $mounted,
                    $route->capability,
                ),
                'active' => $active,
            ];
        }
        foreach ($host->reports() as $report) {
            if (!$report->administratorVisible) {
                continue;
            }
            $entries[] = [
                'noun' => 'report',
                'label' => $report->title,
                'href' => '/administrator/reports/' . $report->identifier(),
                'detail' => sprintf(
                    'Business report over %s records on the Business reports screen; requires the %s capability.',
                    $report->sourceDefinition,
                    $report->requiredCapability,
                ),
                'active' => $active,
            ];
        }

        return $entries;
    }

    /**
     * Describe the portal pages and portal-visible reports the package mounts.
     *
     * @param   ManifestContributions         $contributions  Canonical SDK declaration graph.
     * @param   CanonicalManifestInterpreter  $host           App-owned semantic interpretation.
     * @param   ContributionOwner             $owner          Package owning every declaration in the set.
     * @param   bool                          $active         Whether the extension is currently active.
     *
     * @return  list<SummaryEntry>  Portal-facing entries; empty when none are declared.
     *
     * @since   2.0.0
     */
    private static function portalEntries(
        ManifestContributions $contributions,
        CanonicalManifestInterpreter $host,
        ContributionOwner $owner,
        bool $active,
    ): array {
        $workspaceLabels = [];
        foreach ($contributions->portalWorkspaces() as $workspace) {
            $workspaceLabels[$workspace->id] = $workspace->label;
        }
        $navigation = [];
        foreach ($contributions->portalNavigation() as $item) {
            $navigation[$item->path . '|' . $item->capability] = $item;
        }
        $entries = [];
        foreach ($contributions->portalRoutes() as $route) {
            if (!in_array('GET', $route->methods, true)) {
                continue;
            }
            $mounted = PortalRouteRegistry::routePath($owner, $route);
            $item = $navigation[$route->path . '|' . $route->capability] ?? null;
            $workspace = $item === null ? null : ($workspaceLabels[$item->workspace] ?? null);
            $entries[] = [
                'noun' => 'portal view',
                'label' => $item->label ?? $route->name,
                'href' => str_contains($mounted, '{') ? null : $mounted,
                'detail' => $item === null
                    ? sprintf('Portal members need the %s capability to open it.', $route->capability)
                    : sprintf(
                        'Listed in the portal menu under "%s"; portal members need the %s capability to open it.',
                        $workspace ?? $item->label,
                        $route->capability,
                    ),
                'active' => $active,
            ];
        }
        foreach ($host->reports() as $report) {
            if (!$report->portalVisible) {
                continue;
            }
            $entries[] = [
                'noun' => 'portal report',
                'label' => $report->title,
                'href' => '/portal/reports/' . $report->identifier(),
                'detail' => sprintf(
                    'Report over %s records on the portal reports screen; requires the %s capability.',
                    $report->sourceDefinition,
                    $report->requiredCapability,
                ),
                'active' => $active,
            ];
        }

        return $entries;
    }

    /**
     * Describe listeners, consumers, jobs, and schedules — work with no screen of its own.
     *
     * These entries answer "where does its output go": schedules and jobs point at the Automation
     * screen where their runs are managed, queue consumers name their queue and the worker command
     * that drains it, and in-process listeners say plainly that they act when their event fires
     * rather than anywhere visitable.
     *
     * @param   ManifestContributions         $contributions  Canonical typed integration declarations.
     * @param   CanonicalManifestInterpreter  $host           App-owned schedule-policy interpretation.
     * @param   bool                          $active         Whether the extension is currently active.
     *
     * @return  list<SummaryEntry>  Automation entries; empty when the package runs nothing in the background.
     *
     * @since   2.0.0
     */
    private static function automationEntries(
        ManifestContributions $contributions,
        CanonicalManifestInterpreter $host,
        bool $active,
    ): array {
        $entries = [];
        foreach ($contributions->domainListeners() as $listener) {
            $entries[] = [
                'noun' => 'event listener',
                'label' => $listener->identifier(),
                'href' => null,
                'detail' => sprintf(
                    'Handles %s events synchronously during the request that raises them; it publishes no screen.',
                    $listener->eventType(),
                ),
                'active' => $active,
            ];
        }
        foreach ($contributions->eventConsumers() as $consumer) {
            $entries[] = [
                'noun' => 'queue consumer',
                'label' => $consumer->identifier(),
                'href' => null,
                'detail' => sprintf(
                    'Consumes %s events from the %s queue in the background integration worker.',
                    $consumer->eventType(),
                    $consumer->queue(),
                ),
                'active' => $active,
            ];
        }
        foreach ($contributions->jobs() as $job) {
            $entries[] = [
                'noun' => 'background job',
                'label' => $job->identifier(),
                'href' => '/administrator/automation',
                'detail' => sprintf(
                    'Queued on %s and run by the queue worker; its runs show on the Automation screen.',
                    $job->queue(),
                ),
                'active' => $active,
            ];
        }
        foreach ($host->schedules() as $schedule) {
            $entries[] = [
                'noun' => 'schedule',
                'label' => $schedule->identifier(),
                'href' => '/administrator/automation',
                'detail' => sprintf(
                    'Runs %s (%s), enqueuing the %s job; manage it on the Automation screen.',
                    $schedule->cronExpression(),
                    $schedule->timezone(),
                    $schedule->jobType(),
                ),
                'active' => $active,
            ];
        }
        foreach ($contributions->projections() as $projection) {
            $entries[] = [
                'noun' => 'projection',
                'label' => $projection->identifier(),
                'href' => null,
                'detail' => 'Rebuildable read model maintained in the background; it feeds this '
                    . 'extension\'s reports rather than a screen of its own.',
                'active' => $active,
            ];
        }
        foreach ($contributions->webhooks() as $webhook) {
            $entries[] = [
                'noun' => 'webhook',
                'label' => $webhook->identifier(),
                'href' => null,
                'detail' => 'Outbound delivery adapter; its activity is recorded by the integration worker.',
                'active' => $active,
            ];
        }

        return $entries;
    }

    /**
     * Describe the presentation surfaces a template package can dress, with their activation state.
     *
     * A theme's registry status stays disabled until a surface selects it, so activation is read from
     * the theme-activation records instead: dressing the public site links to `/`, and a merely
     * selectable package says exactly which button on the Extensions screen would activate it.
     *
     * @param   ExtensionManifest  $manifest           Manifest whose type gates this group to templates.
     * @param   list<string>       $themeSurfaces      Surfaces the template is activated on, site
     *          assignments spelled `site:<identifier>`.
     * @param   list<string>       $dressableSurfaces  Surfaces the deployed package ships templates for.
     *
     * @return  list<SummaryEntry>  One entry per dressable surface; empty for non-template packages.
     *
     * @since   2.0.0
     */
    private static function themeEntries(
        ExtensionManifest $manifest,
        array $themeSurfaces,
        array $dressableSurfaces,
    ): array {
        if ($manifest->type() !== ExtensionType::Template) {
            return [];
        }
        $entries = [];
        if ($dressableSurfaces === []) {
            $dressableSurfaces = ['site'];
        }
        foreach ($dressableSurfaces as $surface) {
            if ($surface === 'site') {
                $sites = [];
                foreach ($themeSurfaces as $activated) {
                    if (str_starts_with($activated, 'site:')) {
                        $sites[] = substr($activated, 5);
                    }
                }
                $dressing = in_array('site', $themeSurfaces, true) || $sites !== [];
                $entries[] = [
                    'noun' => 'theme surface',
                    'label' => 'Public site theme',
                    'href' => $dressing ? '/' : null,
                    'detail' => $dressing
                        ? ($sites === []
                            ? 'Dressing the public site now.'
                            : sprintf('Dressing the public site for site %s now.', implode(', ', $sites)))
                        : 'Installed and selectable, but not styling anything yet; choose "Use for site" '
                            . 'on the Extensions screen to dress the public site with it.',
                    'active' => $dressing,
                ];
                continue;
            }
            if ($surface === 'administrator') {
                $dressing = in_array('administrator', $themeSurfaces, true);
                $entries[] = [
                    'noun' => 'theme surface',
                    'label' => 'Administrator theme',
                    'href' => $dressing ? '/administrator' : null,
                    'detail' => $dressing
                        ? 'Dressing this administrator interface now.'
                        : 'Installed and selectable, but not styling anything yet; activating it '
                            . 'restyles this administrator interface and demands step-up authentication.',
                    'active' => $dressing,
                ];
            }
        }

        return $entries;
    }

    /**
     * Describe the record types, field types, and custom handlers the package defines.
     *
     * @param   CanonicalManifestInterpreter  $host    App-owned semantic interpretation.
     * @param   bool                          $active  Whether the extension is currently active.
     *
     * @return  list<SummaryEntry>  Content-shaped entries; empty when the package defines no data.
     *
     * @since   2.0.0
     */
    private static function contentEntries(CanonicalManifestInterpreter $host, bool $active): array
    {
        $entries = [];
        foreach ($host->businessDefinitions() as $definition) {
            $detail = 'Structured records browsed under the Business workspaces screen.';
            if ($definition->portalExposure) {
                $detail = sprintf(
                    'Structured records browsed under the Business workspaces screen; portal members '
                    . 'with access also see them at /portal/business/%s.',
                    $definition->handle,
                );
            }
            $entries[] = [
                'noun' => 'record type',
                'label' => $definition->pluralLabel,
                'href' => $definition->administratorExposure
                    ? '/administrator/business/' . $definition->handle
                    : null,
                'detail' => $detail,
                'active' => $active,
            ];
        }
        foreach ($host->fieldTypes() as $fieldType) {
            $entries[] = [
                'noun' => 'field type',
                'label' => $fieldType->label,
                'href' => null,
                'detail' => sprintf(
                    'Field type %s, offered wherever content models and business definitions pick field types.',
                    $fieldType->id,
                ),
                'active' => $active,
            ];
        }
        foreach ($host->customBusinessViews() as $view) {
            $entries[] = [
                'noun' => 'view handler',
                'label' => $view->handler,
                'href' => null,
                'detail' => 'Custom view handler rendered inside this extension\'s business record views.',
                'active' => $active,
            ];
        }
        foreach ($host->customBusinessActions() as $action) {
            $entries[] = [
                'noun' => 'action handler',
                'label' => $action->handler,
                'href' => null,
                'detail' => 'Custom action handler run from this extension\'s business record actions.',
                'active' => $active,
            ];
        }

        return $entries;
    }

    /**
     * Describe the capability codes the package adds, and where an operator grants them.
     *
     * Contributed capabilities enter the catalog on install but are granted to nobody, so every
     * declared screen stays invisible until an operator assigns them — this group is the pointer
     * from the package to that granting step.
     *
     * @param   CanonicalManifestInterpreter  $host    App-owned semantic interpretation.
     * @param   bool                          $active  Whether the extension is currently active.
     *
     * @return  list<SummaryEntry>  One entry per declared capability; empty when none are declared.
     *
     * @since   2.0.0
     */
    private static function capabilityEntries(CanonicalManifestInterpreter $host, bool $active): array
    {
        $entries = [];
        foreach ($host->capabilities() as $capability) {
            $entries[] = [
                'noun' => 'capability',
                'label' => $capability->id,
                'href' => '/administrator/access',
                'detail' => sprintf(
                    '%s — granted to no role automatically; assign it on the Users & access screen '
                    . 'so the surfaces above appear for those roles.',
                    $capability->label,
                ),
                'active' => $active && $capability->lifecycle->enforceable(),
            ];
        }

        return $entries;
    }
}
