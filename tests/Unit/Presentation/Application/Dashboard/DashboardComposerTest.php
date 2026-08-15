<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application\Dashboard;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceQuery;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardView;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardWidget;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardWorkflowCatalog;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardWorkflowPage;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\CMS\Application\Presentation\Preference\PresentationPreferencePolicy;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceResolver;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Tests\Support\InMemoryPresentationAccessGroupRepository;
use Kumwe\CMS\Tests\Support\InMemoryPresentationPreferenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves access-aware dashboard projection, preference ordering, and safe lifecycle fallback.
 *
 * @since  2.0.0
 */
#[CoversClass(DashboardComposer::class)]
#[CoversClass(DashboardView::class)]
#[CoversClass(DashboardWidget::class)]
#[CoversClass(DashboardWorkflowCatalog::class)]
#[CoversClass(DashboardWorkflowPage::class)]
final class DashboardComposerTest extends TestCase
{
    /**
     * Proves derived defaults include only navigation already admitted to the selected delivery area.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDerivedDefaultsUseFilteredNavigationAndProjectExtensionWorkflows(): void
    {
        $context = AuthorizationContext::human([]);
        $view = $this->composer()->compose(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            $context,
            [
                $this->navigation('core.dashboard', '/administrator', 'Dashboard'),
                $this->navigation(
                    'acme.dashboard-alias',
                    '/administrator/?from=extension',
                    'Dashboard home alias',
                ),
                $this->navigation(
                    'core.dashboard-alias',
                    '/administrator/dashboard',
                    'Dashboard alias',
                ) + ['surface' => 'core.administrator.dashboard'],
                $this->navigation('core.content', '/administrator/content', 'Content'),
                $this->navigation(
                    '2acme.sales__orders',
                    '/administrator/extensions/acme/sales/orders',
                    'Sales orders',
                ),
                $this->navigation('core.portal-security', '/portal/security', 'Portal security'),
            ],
            [$this->contextWidget()],
        );

        self::assertSame(
            ['core.dashboard.context', 'core.content', '2acme.sales__orders'],
            $view->selectedWidgetIds,
        );
        self::assertSame(['core.content', '2acme.sales__orders'], $view->selectedShortcutIds);
        self::assertSame('workflow', $view->widgets[1]->kind);
        self::assertSame('core.content', $view->widgets[1]->id);
        self::assertSame('/administrator/content', $view->widgets[1]->href);
        self::assertFalse($view->widgets[1]->messageIds);
        self::assertSame(['dashboard.navigation.area-mismatch'], $view->diagnostics);
    }

    /**
     * Proves candidates beyond the former 128-row prefix remain reachable through page and full search.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPagesAndSearchesTheCompleteLiveNavigationCatalog(): void
    {
        $navigation = [];
        for ($index = 1; $index <= 500; $index++) {
            $suffix = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $navigation[] = $this->navigation(
                'acme.workflow-' . $suffix,
                '/administrator/workflow-' . $suffix,
                'Workflow ' . $suffix,
            );
        }

        $identifiers = DashboardComposer::workflowIdentifiers(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            $navigation,
        );

        $view = $this->composer()->compose(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            AuthorizationContext::human([]),
            $navigation,
            [],
            [],
            [],
            new DashboardPreferenceQuery(workflowPage: 5),
        );
        $available = $view->toArray()['available_shortcuts'];

        self::assertCount(500, $identifiers);
        self::assertSame('acme.workflow-001', $identifiers[0]);
        self::assertSame('acme.workflow-500', $identifiers[499]);
        self::assertCount(32, $available);
        self::assertSame('acme.workflow-129', $available[0]['id']);
        self::assertSame('acme.workflow-160', $available[31]['id']);
        self::assertSame([], $view->diagnostics);

        $searched = $this->composer()->compose(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            AuthorizationContext::human([]),
            $navigation,
            [],
            [],
            [],
            new DashboardPreferenceQuery(workflowSearch: 'acme.workflow-500'),
        );
        self::assertSame(
            ['acme.workflow-500'],
            array_column($searched->toArray()['available_shortcuts'], 'id'),
        );
    }

    /**
     * Proves the numeric workflow window is explicit and exact search reaches candidates beyond it.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testWorkflowBrowseLimitRequiresSearchBeyondTheNumericWindow(): void
    {
        $navigation = [];
        for ($index = 1; $index <= 3_201; $index++) {
            $suffix = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
            $navigation[] = $this->navigation(
                'acme.workflow-' . $suffix,
                '/administrator/workflow-' . $suffix,
                'Workflow ' . $suffix,
            );
        }
        $catalog = new DashboardWorkflowCatalog(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            $navigation,
        );
        $lastNumericPage = $catalog->page(new DashboardPreferenceQuery(
            workflowPage: DashboardPreferenceQuery::MAXIMUM_PAGE,
        ));

        self::assertCount(32, $lastNumericPage->candidates);
        self::assertFalse($lastNumericPage->hasNext);
        self::assertTrue($lastNumericPage->browseLimit);

        $exact = $catalog->page(new DashboardPreferenceQuery(workflowSearch: 'acme.workflow-3201'));
        self::assertSame(['acme.workflow-3201'], array_map(
            static fn (DashboardWidget $widget): string => $widget->id,
            $exact->candidates,
        ));

        $completeAtLimit = new DashboardWorkflowCatalog(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            array_slice($navigation, 0, 3_200),
        );
        self::assertFalse($completeAtLimit->page(new DashboardPreferenceQuery(
            workflowPage: DashboardPreferenceQuery::MAXIMUM_PAGE,
        ))->browseLimit);
    }

    /**
     * Proves the shared mutation projection cannot reinterpret a public surface as a portal dashboard.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWorkflowIdentifierProjectionRejectsUnsupportedAreas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only to administrator and portal areas');

        DashboardComposer::workflowIdentifiers(
            SurfaceArea::Public,
            SurfaceId::fromString('core.public.home'),
            [],
        );
    }

    /**
     * Proves stored order survives intersection and selectable catalogs put selected items first.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPreferenceOrderIsPreservedWhileStaleIdentifiersArePruned(): void
    {
        $context = AuthorizationContext::human([]);
        $preferences = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $this->seed(
            $preferences,
            $surface,
            $context->actorId(),
            CustomizationSlot::DashboardCards,
            ['2acme.sales__orders', 'core.dashboard.content-summary', 'missing.denied'],
        );
        $this->seed(
            $preferences,
            $surface,
            $context->actorId(),
            CustomizationSlot::NavigationShortcuts,
            ['core.settings', 'missing.denied', 'core.content'],
        );

        $view = $this->composer($preferences)->compose(
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            $context,
            [
                $this->navigation('core.content', '/administrator/content', 'Content'),
                $this->navigation('core.settings', '/administrator/settings', 'Settings'),
                $this->navigation(
                    '2acme.sales__orders',
                    '/administrator/extensions/acme/sales/orders',
                    'Sales orders',
                ),
            ],
            [$this->summaryWidget(), $this->contextWidget()],
            ['core.dashboard.context', 'core.dashboard.content-summary'],
            ['core.content'],
        );

        self::assertSame(['2acme.sales__orders', 'core.dashboard.content-summary'], $view->selectedWidgetIds);
        self::assertSame(['core.settings', 'core.content'], $view->selectedShortcutIds);
        self::assertSame(
            [
                '2acme.sales__orders',
                'core.dashboard.content-summary',
                'core.dashboard.context',
                'core.content',
                'core.settings',
            ],
            array_column($view->toArray()['available_widgets'], 'id'),
        );
        self::assertSame(
            ['core.settings', 'core.content', '2acme.sales__orders'],
            array_column($view->toArray()['available_shortcuts'], 'id'),
        );
        self::assertSame(
            ['dashboard.widgets.selection-pruned', 'dashboard.shortcuts.selection-pruned'],
            $view->diagnostics,
        );
        self::assertSame(
            ['dashboard_cards' => true, 'navigation_shortcuts' => true],
            $view->toArray()['customized'],
        );
        self::assertSame(
            ['dashboard_cards' => 'user', 'navigation_shortcuts' => 'user'],
            $view->toArray()['source'],
        );
    }

    /**
     * Proves an entirely stale non-empty stored selection falls back to live curated defaults.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAllStaleNonEmptySelectionsFallBackToDefaults(): void
    {
        $context = AuthorizationContext::human([]);
        $preferences = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $this->seed(
            $preferences,
            $surface,
            $context->actorId(),
            CustomizationSlot::DashboardCards,
            ['disabled.extension-widget'],
        );
        $this->seed(
            $preferences,
            $surface,
            $context->actorId(),
            CustomizationSlot::NavigationShortcuts,
            ['disabled.extension-route'],
        );

        $view = $this->composer($preferences)->compose(
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            $context,
            [$this->navigation('core.content', '/administrator/content', 'Content')],
            [$this->summaryWidget()],
            ['core.dashboard.content-summary', 'core.content'],
            ['core.content'],
        );

        self::assertSame(['core.dashboard.content-summary', 'core.content'], $view->selectedWidgetIds);
        self::assertSame(['core.content'], $view->selectedShortcutIds);
        self::assertSame([
            'dashboard.widgets.selection-pruned',
            'dashboard.widgets.selection-fallback',
            'dashboard.shortcuts.selection-pruned',
            'dashboard.shortcuts.selection-fallback',
        ], $view->diagnostics);
    }

    /**
     * Proves a stored empty list remains intentional and never receives default cards or shortcuts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomizedEmptySelectionsRemainEmpty(): void
    {
        $context = AuthorizationContext::human([]);
        $preferences = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $this->seed(
            $preferences,
            $surface,
            $context->actorId(),
            CustomizationSlot::DashboardCards,
            [],
        );
        $this->seed(
            $preferences,
            $surface,
            $context->actorId(),
            CustomizationSlot::NavigationShortcuts,
            [],
        );

        $view = $this->composer($preferences)->compose(
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            $context,
            [$this->navigation('core.content', '/administrator/content', 'Content')],
            [$this->summaryWidget()],
            ['core.dashboard.content-summary', 'core.content'],
            ['core.content'],
        );

        self::assertSame([], $view->selectedWidgetIds);
        self::assertSame([], $view->selectedShortcutIds);
        self::assertSame([], $view->diagnostics);
        self::assertSame(
            ['core.dashboard.content-summary', 'core.content'],
            array_column($view->toArray()['available_widgets'], 'id'),
        );
    }

    /**
     * Proves explicit empty defaults differ from null access-aware default derivation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExplicitEmptyDefaultsProduceAnEmptyUncustomizedDashboard(): void
    {
        $view = $this->composer()->compose(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            AuthorizationContext::human([]),
            [$this->navigation('core.content', '/administrator/content', 'Content')],
            [$this->summaryWidget()],
            [],
            [],
        );

        self::assertSame([], $view->selectedWidgetIds);
        self::assertSame([], $view->selectedShortcutIds);
        self::assertFalse($view->widgetsCustomized);
        self::assertFalse($view->shortcutsCustomized);
        self::assertSame(['core.dashboard.content-summary', 'core.content'], array_column(
            $view->toArray()['available_widgets'],
            'id',
        ));
    }

    /**
     * Proves explicit defaults use the same canonical dotted grammar as live widget identifiers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExplicitDefaultsRejectNonDottedIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lowercase dotted name');
        $this->composer()->compose(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            AuthorizationContext::human([]),
            [],
            [],
            ['legacy-card'],
            [],
        );
    }

    /**
     * Proves direct and exact-current-membership group lists form one stable union without cross-membership leakage.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccessGroupSelectionsUnionDeterministically(): void
    {
        $currentMembershipId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb305';
        $unrelatedMembershipId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb306';
        $context = AuthorizationContext::human(
            [],
            membership: AuthorizationContext::membership(membershipId: $currentMembershipId),
        );
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $owner = ContributionOwner::core();
        $preferences = new InMemoryPresentationPreferenceRepository();
        $early = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
            'operations',
            'Operations',
        );
        $late = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb304',
            'editors',
            'Editors',
        );
        $unrelated = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb307',
            'finance',
            'Finance',
        );
        $this->seed(
            $preferences,
            $surface,
            $early->id,
            CustomizationSlot::DashboardCards,
            ['core.settings', 'core.dashboard.content-summary'],
            CustomizationScope::RoleWorkspace,
            3,
        );
        $this->seed(
            $preferences,
            $surface,
            $late->id,
            CustomizationSlot::DashboardCards,
            ['core.content', 'core.settings'],
            CustomizationScope::RoleWorkspace,
            4,
        );
        $this->seed(
            $preferences,
            $surface,
            $unrelated->id,
            CustomizationSlot::DashboardCards,
            ['core.access'],
            CustomizationScope::RoleWorkspace,
            5,
        );
        $groups = new InMemoryPresentationAccessGroupRepository(
            [$late, $early, $unrelated],
            [$context->actorId() => [$early->id]],
            [
                $currentMembershipId => [$late->id],
                $unrelatedMembershipId => [$unrelated->id],
            ],
        );

        $view = $this->composer($preferences, $groups)->compose(
            SurfaceArea::Administrator,
            $surface,
            $owner,
            $context,
            [
                $this->navigation('core.content', '/administrator/content', 'Content'),
                $this->navigation('core.settings', '/administrator/settings', 'Settings'),
                $this->navigation('core.access', '/administrator/access', 'Access'),
            ],
            [$this->summaryWidget()],
        );

        self::assertSame(
            ['core.settings', 'core.dashboard.content-summary', 'core.content'],
            $view->selectedWidgetIds,
        );
        self::assertNotContains('core.access', $view->selectedWidgetIds);
        self::assertSame(CustomizationScope::RoleWorkspace, $view->widgetSource);
        self::assertNull($view->widgetVersion);
        self::assertSame(1, $groups->contextQueryCount());
        self::assertSame(['find' => 0, 'find_many' => 2], $preferences->readCounts());
    }

    /**
     * Proves portal composition cannot project administrator navigation or its own landing-page link.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalCompositionIsIsolatedFromAdministratorNavigation(): void
    {
        $view = $this->composer()->compose(
            SurfaceArea::Portal,
            SurfaceId::fromString('core.portal.home'),
            ContributionOwner::core(),
            AuthorizationContext::human([]),
            [
                $this->navigation('core.portal-home', '/portal', 'Overview'),
                $this->navigation('core.portal-reports', '/portal/reports', 'Reports'),
                $this->navigation('core.settings', '/administrator/settings', 'Settings'),
            ],
            [$this->contextWidget()],
        );

        self::assertSame(['core.dashboard.context', 'core.portal-reports'], $view->selectedWidgetIds);
        self::assertSame(['core.portal-reports'], $view->selectedShortcutIds);
        self::assertNotContains('core.settings', array_column($view->toArray()['available_widgets'], 'id'));
        self::assertSame(['dashboard.navigation.area-mismatch'], $view->diagnostics);
    }

    /**
     * Proves caller widgets cannot smuggle a navigation-owned href into composition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCallerSuppliedWorkflowWidgetsAreRejected(): void
    {
        $workflow = DashboardWidget::fromNavigation(
            $this->navigation('core.content', '/administrator/content', 'Content'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot carry navigation workflows');
        $this->composer()->compose(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            AuthorizationContext::human([]),
            [],
            [$workflow],
        );
    }

    /**
     * Build the real resolver-backed composer around deterministic in-memory repositories.
     *
     * @param   ?InMemoryPresentationPreferenceRepository $preferences  Optional seeded preferences.
     * @param   ?InMemoryPresentationAccessGroupRepository $groups       Optional seeded access groups.
     *
     * @return  DashboardComposer  Application service under test.
     *
     * @since   2.0.0
     */
    private function composer(
        ?InMemoryPresentationPreferenceRepository $preferences = null,
        ?InMemoryPresentationAccessGroupRepository $groups = null,
    ): DashboardComposer {
        $policy = $this->createStub(PresentationPreferencePolicy::class);
        $policy->method('allows')->willReturn(true);

        return new DashboardComposer(
            new PresentationPreferenceResolver(
                $preferences ?? new InMemoryPresentationPreferenceRepository(),
                $policy,
            ),
            $groups ?? new InMemoryPresentationAccessGroupRepository(),
        );
    }

    /**
     * Return one translated safe summary widget.
     *
     * @return  DashboardWidget  Core semantic widget fixture.
     *
     * @since   2.0.0
     */
    private function summaryWidget(): DashboardWidget
    {
        return new DashboardWidget(
            'core.dashboard.content-summary',
            DashboardWidget::KIND_SUMMARY,
            'administrator.dashboard.content-summary.title',
            'administrator.dashboard.content-summary.description',
            'content',
            'Content',
            DashboardWidget::SIZE_LARGE,
            [
                'metrics' => [[
                    'label' => 'administrator.dashboard.content-summary.total',
                    'value' => 12,
                    'tone' => 'neutral',
                ]],
            ],
        );
    }

    /**
     * Return one translated safe access-context widget.
     *
     * @return  DashboardWidget  Core semantic widget fixture.
     *
     * @since   2.0.0
     */
    private function contextWidget(): DashboardWidget
    {
        return new DashboardWidget(
            'core.dashboard.context',
            DashboardWidget::KIND_CONTEXT,
            'dashboard.context.title',
            'dashboard.context.description',
            'dashboard',
            'Workspace',
            data: [
                'items' => [[
                    'label' => 'dashboard.context.site',
                    'value' => 'default',
                ]],
            ],
        );
    }

    /**
     * Build one shell-shaped visible navigation row.
     *
     * @param   string  $id     Canonical navigation identifier.
     * @param   string  $href   Root-relative filtered destination.
     * @param   string  $label  Current display label.
     *
     * @return  array<string, int|string>  Visible navigation fixture.
     *
     * @since   2.0.0
     */
    private function navigation(string $id, string $href, string $label): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'description' => 'Open ' . $label . '.',
            'href' => $href,
            'icon' => 'dashboard',
            'group' => 'Workspace',
            'priority' => 10,
        ];
    }

    /**
     * Seed one exact user or access-group list preference.
     *
     * @param   InMemoryPresentationPreferenceRepository $repository  Preference fixture store.
     * @param   SurfaceId                                $surface     Dashboard surface.
     * @param   string                                   $scopeId     User or access-group identity.
     * @param   CustomizationSlot                        $slot        Dashboard list slot.
     * @param   list<string>                             $value       Ordered semantic identifiers.
     * @param   CustomizationScope                       $scope       Preference layer.
     * @param   int                                      $version     Fixture optimistic version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function seed(
        InMemoryPresentationPreferenceRepository $repository,
        SurfaceId $surface,
        string $scopeId,
        CustomizationSlot $slot,
        array $value,
        CustomizationScope $scope = CustomizationScope::User,
        int $version = 1,
    ): void {
        $repository->seed(PresentationPreference::create(
            $surface,
            ContributionOwner::core(),
            $scope,
            $scopeId,
            $slot,
            $value,
            $version,
            AuthorizationContext::SUBJECT,
            new DateTimeImmutable('2026-08-15T12:00:00Z'),
        ));
    }
}
