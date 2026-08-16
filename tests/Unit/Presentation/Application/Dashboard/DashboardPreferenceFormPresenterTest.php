<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application\Dashboard;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceAccessGroupState;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceQuery;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceState;
use Kumwe\CMS\Delivery\Http\Dashboard\DashboardPreferenceFormDecoder;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceFormPresenter;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceFormProjection;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardWidget;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardWorkflowCatalog;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardWorkflowPage;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the presentation-only mapping from typed dashboard preference state to form models.
 *
 * @since  2.0.0
 */
#[CoversClass(DashboardPreferenceFormPresenter::class)]
#[CoversClass(DashboardPreferenceFormProjection::class)]
#[UsesClass(DashboardPreferenceState::class)]
#[UsesClass(DashboardPreferenceAccessGroupState::class)]
#[UsesClass(PresentationAccessGroup::class)]
#[UsesClass(PresentationPreference::class)]
#[UsesClass(DashboardPreferenceFormDecoder::class)]
#[UsesClass(DashboardWorkflowCatalog::class)]
#[UsesClass(DashboardWorkflowPage::class)]
final class DashboardPreferenceFormPresenterTest extends TestCase
{
    /**
     * Proves empty rows, inheritance, role identity, ordering, versions, and browser state remain explicit.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMapsTypedStateWithoutCollapsingInheritanceOrBrowserEvidence(): void
    {
        $surface = SurfaceId::fromString('core.portal.home');
        $actorId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
        $group = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
            'operations',
            'Operations',
        );
        $state = new DashboardPreferenceState(
            $surface,
            $actorId,
            $this->preference(
                $surface,
                CustomizationScope::User,
                $actorId,
                CustomizationSlot::DashboardCards,
                [],
                3,
            ),
            null,
            [new DashboardPreferenceAccessGroupState(
                $surface,
                $group,
                null,
                $this->preference(
                    $surface,
                    CustomizationScope::RoleWorkspace,
                    $group->id,
                    CustomizationSlot::NavigationShortcuts,
                    ['core.portal-business-records'],
                    2,
                ),
            )],
            true,
            new DashboardPreferenceQuery(2, 'Operations'),
            true,
            true,
            false,
        );

        $projection = (new DashboardPreferenceFormPresenter())->present(
            $state,
            ['core.dashboard.access-context'],
            ['2acme.sales__orders', 'core.portal-approvals'],
        );

        self::assertCount(2, $projection->forms);
        self::assertSame([], $projection->forms[0]['selected_widget_ids']);
        self::assertSame(3, $projection->forms[0]['widget_version']);
        self::assertSame(
            ['2acme.sales__orders', 'core.portal-approvals'],
            $projection->forms[0]['selected_shortcut_ids'],
        );
        self::assertSame(
            ['2acme.sales__orders' => 1, 'core.portal-approvals' => 2],
            $projection->forms[0]['shortcut_order'],
        );
        self::assertSame(0, $projection->forms[0]['shortcut_version']);
        self::assertSame($group->id, $projection->forms[1]['scope_id']);
        self::assertSame('Operations', $projection->forms[1]['label']);
        self::assertSame('operations', $projection->forms[1]['group_code']);
        self::assertFalse($projection->forms[1]['message_ids']);
        self::assertSame([], $projection->forms[1]['selected_widget_ids']);
        self::assertSame(['core.portal-business-records'], $projection->forms[1]['selected_shortcut_ids']);
        self::assertSame(2, $projection->forms[1]['shortcut_version']);
        self::assertTrue($projection->accessGroupAdministration);
        self::assertSame(2, $projection->accessGroupQuery->page);
        self::assertSame('Operations', $projection->accessGroupQuery->search);
        self::assertSame(1, $projection->accessGroupResultCount);
        self::assertTrue($projection->accessGroupHasPrevious);
        self::assertTrue($projection->accessGroupHasNext);
        self::assertFalse($projection->accessGroupBrowseLimit);
        self::assertSame([], $projection->diagnostics);
    }

    /**
     * Proves malformed effective fallbacks fail at the presentation boundary.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRejectsAMalformedEffectiveFallbackIdentifier(): void
    {
        $surface = SurfaceId::fromString('core.portal.home');
        $this->expectException(InvalidArgumentException::class);

        (new DashboardPreferenceFormPresenter())->present(new DashboardPreferenceState(
            $surface,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            null,
            null,
            [],
            false,
            new DashboardPreferenceQuery(),
            false,
            false,
            false,
        ), ['not-dotted']);
    }

    /**
     * Proves off-page live choices remain editable while withdrawn choices are pruned from a bounded form.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testKeepsSelectedOffPageChoicesAndAllowsThemToBeUnchecked(): void
    {
        $surface = SurfaceId::fromString('core.portal.home');
        $actorId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
        $navigation = [];
        for ($index = 1; $index <= 40; $index++) {
            $suffix = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $navigation[] = [
                'id' => 'acme.workflow-' . $suffix,
                'label' => 'Workflow ' . $suffix,
                'description' => 'Open workflow ' . $suffix . '.',
                'href' => '/portal/workflows/' . $suffix,
                'icon' => 'dashboard',
                'group' => 'Operations',
            ];
        }
        $catalog = new DashboardWorkflowCatalog(SurfaceArea::Portal, $surface, $navigation);
        $state = new DashboardPreferenceState(
            $surface,
            $actorId,
            $this->preference(
                $surface,
                CustomizationScope::User,
                $actorId,
                CustomizationSlot::DashboardCards,
                ['core.dashboard.access-context', 'acme.workflow-040', 'acme.disabled'],
                4,
            ),
            $this->preference(
                $surface,
                CustomizationScope::User,
                $actorId,
                CustomizationSlot::NavigationShortcuts,
                ['acme.workflow-040', 'acme.disabled'],
                5,
            ),
            [],
            false,
            new DashboardPreferenceQuery(workflowPage: 1),
            false,
            false,
            false,
        );
        $core = new DashboardWidget(
            'core.dashboard.access-context',
            DashboardWidget::KIND_CONTEXT,
            'core.portal.dashboard.access_context.title',
            'core.portal.dashboard.access_context.description',
            data: ['items' => [[
                'label' => 'core.portal.dashboard.access_context.site_label',
                'value' => 'default',
            ]]],
        );

        $projection = (new DashboardPreferenceFormPresenter())->present(
            $state,
            [],
            [],
            $catalog,
            [$core],
        );
        $form = $projection->forms[0];

        self::assertSame(
            ['core.dashboard.access-context', 'acme.workflow-040'],
            $form['selected_widget_ids'],
        );
        self::assertSame(['acme.workflow-040'], $form['selected_shortcut_ids']);
        self::assertSame('acme.workflow-040', $form['available_shortcuts'][0]['id']);
        self::assertCount(33, $form['available_shortcuts']);
        self::assertNotContains('acme.disabled', array_column($form['available_widgets'], 'id'));

        $post = [
            'action' => 'navigation-shortcuts.save',
            'scope' => 'user',
            'scope_id' => $actorId,
            'expected_version' => '5',
        ];
        foreach ($form['available_shortcuts'] as $index => $choice) {
            $post['item_' . $index] = $choice['id'];
            $post['order_' . $index] = (string) ($index + 1);
        }
        $mutation = (new DashboardPreferenceFormDecoder())->decode($post);

        self::assertContains('acme.workflow-040', $mutation->submittedIds);
        self::assertNotContains('acme.workflow-040', $mutation->selectedIds);
    }

    /**
     * Proves the densest valid per-form candidate union remains below the browser-form ceiling.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testBoundsEachDenseFormWithoutAUnionAcrossScopes(): void
    {
        $surface = SurfaceId::fromString('core.portal.home');
        $actorId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
        $navigation = [];
        for ($index = 1; $index <= 96; $index++) {
            $suffix = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $navigation[] = [
                'id' => 'acme.workflow-' . $suffix,
                'label' => 'Workflow ' . $suffix,
                'description' => 'Open workflow ' . $suffix . '.',
                'href' => '/portal/workflows/' . $suffix,
                'icon' => 'dashboard',
                'group' => 'Operations',
            ];
        }
        $selectedWidgets = [];
        for ($index = 33; $index <= 96; $index++) {
            $selectedWidgets[] = 'acme.workflow-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        }
        $selectedShortcuts = array_slice($selectedWidgets, 32);
        $core = [];
        for ($index = 1; $index <= 64; $index++) {
            $core[] = new DashboardWidget(
                'core.dashboard.context-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                DashboardWidget::KIND_CONTEXT,
                'core.portal.dashboard.access_context.title',
                'core.portal.dashboard.access_context.description',
                data: ['items' => [[
                    'label' => 'core.portal.dashboard.access_context.site_label',
                    'value' => 'default',
                ]]],
            );
        }
        $state = new DashboardPreferenceState(
            $surface,
            $actorId,
            $this->preference(
                $surface,
                CustomizationScope::User,
                $actorId,
                CustomizationSlot::DashboardCards,
                $selectedWidgets,
                2,
            ),
            $this->preference(
                $surface,
                CustomizationScope::User,
                $actorId,
                CustomizationSlot::NavigationShortcuts,
                $selectedShortcuts,
                2,
            ),
            [],
            false,
            new DashboardPreferenceQuery(),
            false,
            false,
            false,
        );

        $form = (new DashboardPreferenceFormPresenter())->present(
            $state,
            [],
            [],
            new DashboardWorkflowCatalog(SurfaceArea::Portal, $surface, $navigation),
            $core,
        )->forms[0];

        self::assertCount(160, $form['available_widgets']);
        self::assertCount(64, $form['available_shortcuts']);
    }

    /**
     * Build one exact dashboard list preference for presentation mapping.
     *
     * @param   SurfaceId            $surface  Dashboard surface receiving the row.
     * @param   CustomizationScope   $scope    Personal or canonical access-group layer.
     * @param   string               $scopeId  Actor or stable access-group identity.
     * @param   CustomizationSlot    $slot     Dashboard cards or navigation shortcuts.
     * @param   list<string>         $value    Exact ordered semantic identifiers.
     * @param   int                  $version  Positive optimistic version.
     *
     * @return  PresentationPreference  Validated exact row.
     *
     * @since   2.0.0
     */
    private function preference(
        SurfaceId $surface,
        CustomizationScope $scope,
        string $scopeId,
        CustomizationSlot $slot,
        array $value,
        int $version,
    ): PresentationPreference {
        return PresentationPreference::create(
            $surface,
            ContributionOwner::core(),
            $scope,
            $scopeId,
            $slot,
            $value,
            $version,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            new DateTimeImmutable('2026-08-15T11:00:00Z'),
        );
    }
}
