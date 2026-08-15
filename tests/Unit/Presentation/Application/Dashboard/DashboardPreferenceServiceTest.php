<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application\Dashboard;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceService;
use Kumwe\CMS\Presentation\Application\Preference\PresentationAccessGroup;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceManager;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Tests\Support\DashboardPreferenceTestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies exact dashboard form projection and strict live-catalog mutation delivery.
 *
 * @since  2.0.0
 */
#[CoversClass(DashboardPreferenceService::class)]
#[UsesClass(PresentationPreferenceManager::class)]
final class DashboardPreferenceServiceTest extends TestCase
{
    /**
     * Canonical role UUID used by access-group scenarios.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ROLE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb303';

    /**
     * Proves exact rows retain their own versions and order while only missing personal rows use fallbacks.
     *
     * Portal deliberately exercises access-group forms too: area does not grant authority, `users.manage` does.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBuildsExactPersonalAndAuthorizedPortalAccessGroupForms(): void
    {
        $group = PresentationAccessGroup::fromRole(self::ROLE_ID, 'operations', 'Operations');
        $runtime = new DashboardPreferenceTestRuntime([$group]);
        $context = AuthorizationContext::human(['users.manage']);
        $surface = SurfaceId::fromString('core.portal.home');
        $this->seed(
            $runtime,
            $surface,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::DashboardCards,
            ['core.portal-approvals', 'core.dashboard.access-context'],
            4,
        );
        $this->seed(
            $runtime,
            $surface,
            CustomizationScope::RoleWorkspace,
            $group->id,
            CustomizationSlot::NavigationShortcuts,
            ['core.portal-business-records'],
            2,
        );

        $forms = $runtime->service->formModels(
            $context,
            SurfaceArea::Portal,
            $surface,
            ContributionOwner::core(),
            true,
            ['core.dashboard.access-context'],
            ['2acme.sales__orders', 'core.portal-approvals'],
        );

        self::assertCount(2, $forms);
        self::assertSame('user', $forms[0]['scope']);
        self::assertSame('core.interface_standard.dashboard.personal_label', $forms[0]['label']);
        self::assertTrue($forms[0]['message_ids']);
        self::assertSame(
            ['core.portal-approvals', 'core.dashboard.access-context'],
            $forms[0]['selected_widget_ids'],
        );
        self::assertSame([
            'core.portal-approvals' => 1,
            'core.dashboard.access-context' => 2,
        ], $forms[0]['widget_order']);
        self::assertSame(4, $forms[0]['widget_version']);
        self::assertSame(
            ['2acme.sales__orders', 'core.portal-approvals'],
            $forms[0]['selected_shortcut_ids'],
        );
        self::assertSame(['2acme.sales__orders' => 1, 'core.portal-approvals' => 2], $forms[0]['shortcut_order']);
        self::assertSame(0, $forms[0]['shortcut_version']);

        self::assertSame('role-workspace', $forms[1]['scope']);
        self::assertSame($group->id, $forms[1]['scope_id']);
        self::assertSame('Operations', $forms[1]['label']);
        self::assertFalse($forms[1]['message_ids']);
        self::assertSame([], $forms[1]['selected_widget_ids']);
        self::assertSame([], $forms[1]['widget_order']);
        self::assertSame(0, $forms[1]['widget_version']);
        self::assertSame(['core.portal-business-records'], $forms[1]['selected_shortcut_ids']);
        self::assertSame(['core.portal-business-records' => 1], $forms[1]['shortcut_order']);
        self::assertSame(2, $forms[1]['shortcut_version']);
    }

    /**
     * Proves requested groups are individually omitted when manager authorization refuses exact role export.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOmitsUnauthorizedGroupsWithoutHidingThePersonalForm(): void
    {
        $group = PresentationAccessGroup::fromRole(self::ROLE_ID, 'operations', 'Operations');
        $runtime = new DashboardPreferenceTestRuntime([$group]);
        $context = AuthorizationContext::human(['portal.access']);

        $forms = $runtime->service->formModels(
            $context,
            SurfaceArea::Portal,
            SurfaceId::fromString('core.portal.home'),
            ContributionOwner::core(),
            true,
            ['core.dashboard.access-context'],
            [],
        );

        self::assertCount(1, $forms);
        self::assertSame($context->actorId(), $forms[0]['scope_id']);
        self::assertSame(['core.dashboard.access-context'], $forms[0]['selected_widget_ids']);
    }

    /**
     * Proves a stored intentional empty personal list wins over a non-empty effective fallback.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExactEmptyPersonalRowWinsOverTheRenderedFallback(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $context = AuthorizationContext::human(['portal.access']);
        $surface = SurfaceId::fromString('core.portal.home');
        $this->seed(
            $runtime,
            $surface,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::DashboardCards,
            [],
            3,
        );

        $forms = $runtime->service->formModels(
            $context,
            SurfaceArea::Portal,
            $surface,
            ContributionOwner::core(),
            false,
            ['core.dashboard.access-context'],
            [],
        );

        self::assertSame([], $forms[0]['selected_widget_ids']);
        self::assertSame([], $forms[0]['widget_order']);
        self::assertSame(3, $forms[0]['widget_version']);
    }

    /**
     * Proves save reconstructs checked identifiers by exact submitted order and reset deletes the exact row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSavesOrderedPersonalSelectionAndResetsItOptimistically(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $context = AuthorizationContext::human(['administrator.access', 'content.read']);
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $key = new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            $context->actorId(),
        );
        $runtime->service->mutate(
            $context,
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            [
                'action' => 'dashboard-cards.save',
                'scope' => 'user',
                'scope_id' => $context->actorId(),
                'expected_version' => '0',
                'item_0' => 'core.dashboard.content-summary',
                'selected_0' => '1',
                'order_0' => '20',
                'item_1' => '2acme.sales__orders',
                'selected_1' => '1',
                'order_1' => '10',
                'item_2' => 'core.settings',
                'order_2' => '1',
            ],
            ['core.dashboard.content-summary', '2acme.sales__orders', 'core.settings'],
            [],
        );

        self::assertSame(
            ['2acme.sales__orders', 'core.dashboard.content-summary'],
            $runtime->preferences->find($key)?->value()->value(),
        );
        self::assertSame(1, $runtime->preferences->find($key)?->version());

        $runtime->service->mutate(
            $context,
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            [
                'action' => 'dashboard-cards.reset',
                'scope' => 'user',
                'scope_id' => $context->actorId(),
                'expected_version' => '1',
                'item_stale' => 'withdrawn.widget',
            ],
            [],
            [],
        );

        self::assertNull($runtime->preferences->find($key));
    }

    /**
     * Proves portal delivery admits a canonical access-group target when exact role authority is present.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalCanSaveAnAuthorizedAccessGroupSelection(): void
    {
        $group = PresentationAccessGroup::fromRole(self::ROLE_ID, 'operations', 'Operations');
        $runtime = new DashboardPreferenceTestRuntime([$group]);
        $context = AuthorizationContext::human(['portal.access', 'users.manage']);
        $surface = SurfaceId::fromString('core.portal.home');
        $runtime->service->mutate(
            $context,
            SurfaceArea::Portal,
            $surface,
            ContributionOwner::core(),
            [
                'action' => 'dashboard-cards.save',
                'scope' => 'role-workspace',
                'scope_id' => $group->id,
                'expected_version' => '0',
                'item_0' => 'core.dashboard.access-context',
                'selected_0' => '1',
                'order_0' => '1',
            ],
            ['core.dashboard.access-context'],
            [],
        );

        $stored = $runtime->preferences->find(new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::DashboardCards,
            CustomizationScope::RoleWorkspace,
            $group->id,
        ));
        self::assertSame(['core.dashboard.access-context'], $stored?->value()->value());
        self::assertSame(['group:' . $group->id], $runtime->groups->locks());
    }

    /**
     * Proves portal area alone never grants access-group preference authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalAccessGroupSaveStillRequiresUsersManage(): void
    {
        $group = PresentationAccessGroup::fromRole(self::ROLE_ID, 'operations', 'Operations');
        $runtime = new DashboardPreferenceTestRuntime([$group]);
        $context = AuthorizationContext::human(['portal.access']);
        $this->expectException(AuthorizationDenied::class);

        $runtime->service->mutate(
            $context,
            SurfaceArea::Portal,
            SurfaceId::fromString('core.portal.home'),
            ContributionOwner::core(),
            [
                'action' => 'dashboard-cards.save',
                'scope' => 'role-workspace',
                'scope_id' => $group->id,
                'expected_version' => '0',
            ],
            [],
            [],
        );
    }

    /**
     * Proves an effective personal fallback is subject to the canonical `SurfaceId` grammar too.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAMalformedEffectiveFallbackIdentifier(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $context = AuthorizationContext::human(['portal.access']);
        $this->expectException(InvalidArgumentException::class);

        $runtime->service->formModels(
            $context,
            SurfaceArea::Portal,
            SurfaceId::fromString('core.portal.home'),
            ContributionOwner::core(),
            false,
            ['not-dotted'],
            [],
        );
    }

    /**
     * Proves malformed flat form projections fail before any preference row is written.
     *
     * @param   array<string, string>  $form     Candidate malicious or ambiguous form.
     * @param   list<string>           $allowed  Current caller-supplied widget catalog.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidForms')]
    public function testRejectsMalformedDuplicateOrderedAndUnknownSelections(array $form, array $allowed): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $context = AuthorizationContext::human(['administrator.access']);
        $this->expectException(InvalidArgumentException::class);

        $runtime->service->mutate(
            $context,
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            $form,
            $allowed,
            [],
        );
    }

    /**
     * Supply invalid forms covering identity, syntax, duplicate, live-catalog, and order ambiguity.
     *
     * @return  iterable<string, array{array<string, string>, list<string>}>  Invalid scenario arguments.
     *
     * @since   2.0.0
     */
    public static function invalidForms(): iterable
    {
        $base = [
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '0',
        ];
        yield 'unknown live identifier' => [[
            ...$base,
            'item_0' => 'withdrawn.widget',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['core.settings']];
        yield 'duplicate identifier' => [[
            ...$base,
            'item_0' => 'core.settings',
            'order_0' => '1',
            'item_1' => 'core.settings',
            'order_1' => '2',
        ], ['core.settings']];
        yield 'duplicate order' => [[
            ...$base,
            'item_0' => 'core.settings',
            'selected_0' => '1',
            'order_0' => '1',
            'item_1' => 'core.access',
            'selected_1' => '1',
            'order_1' => '1',
        ], ['core.settings', 'core.access']];
        yield 'missing order' => [[
            ...$base,
            'item_0' => 'core.settings',
            'selected_0' => '1',
        ], ['core.settings']];
        yield 'non-contiguous index' => [[
            ...$base,
            'item_1' => 'core.settings',
            'order_1' => '1',
        ], ['core.settings']];
        yield 'selected index without item' => [[
            ...$base,
            'selected_0' => '1',
        ], ['core.settings']];
        yield 'non-canonical index' => [[
            ...$base,
            'item_00' => 'core.settings',
            'order_00' => '1',
        ], ['core.settings']];
        yield 'foreign user' => [[
            ...$base,
            'scope_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
        ], []];
        yield 'malformed role' => [[
            ...$base,
            'scope' => 'role-workspace',
            'scope_id' => 'role:operations',
        ], []];
        yield 'unsupported action' => [[...$base, 'action' => 'dashboard-cards.publish'], []];
        yield 'non-canonical version' => [[...$base, 'expected_version' => '01'], []];
        yield 'version above integer range' => [[
            ...$base,
            'expected_version' => str_repeat('9', strlen((string) PHP_INT_MAX)),
        ], []];
        yield 'invalid selection flag' => [[
            ...$base,
            'item_0' => 'core.settings',
            'selected_0' => 'yes',
            'order_0' => '1',
        ], ['core.settings']];
        yield 'duplicate live allowlist' => [$base, ['core.settings', 'core.settings']];
        yield 'invalid surface grammar in allowlist' => [$base, ['core']];
    }

    /**
     * Proves a dashboard-card form cannot select more than the KIS slot maximum.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsASelectionBeyondTheDashboardCardLimit(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $context = AuthorizationContext::human(['administrator.access']);
        $form = [
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => $context->actorId(),
            'expected_version' => '0',
        ];
        $allowed = [];
        for ($index = 0; $index < 65; $index++) {
            $identifier = 'core.widget-' . $index;
            $allowed[] = $identifier;
            $form['item_' . $index] = $identifier;
            $form['selected_' . $index] = '1';
            $form['order_' . $index] = (string) ($index + 1);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KIS limit');

        $runtime->service->mutate(
            $context,
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            $form,
            $allowed,
            [],
        );
    }

    /**
     * Seed one exact row without exercising mutation behavior irrelevant to form projection.
     *
     * @param   DashboardPreferenceTestRuntime  $runtime  In-memory test runtime receiving the row.
     * @param   SurfaceId                      $surface  Exact dashboard surface.
     * @param   CustomizationScope             $scope    Personal or access-group hierarchy layer.
     * @param   string                         $scopeId  Actor or stable group identity.
     * @param   CustomizationSlot              $slot     Dashboard list slot.
     * @param   list<string>                   $value    Ordered semantic identifiers.
     * @param   int                            $version  Positive fixture version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function seed(
        DashboardPreferenceTestRuntime $runtime,
        SurfaceId $surface,
        CustomizationScope $scope,
        string $scopeId,
        CustomizationSlot $slot,
        array $value,
        int $version,
    ): void {
        $runtime->preferences->seed(PresentationPreference::create(
            $surface,
            ContributionOwner::core(),
            $scope,
            $scopeId,
            $slot,
            $value,
            $version,
            AuthorizationContext::SUBJECT,
            new DateTimeImmutable('2026-08-15T11:00:00+00:00'),
        ));
    }
}
