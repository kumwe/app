<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\InterfaceStandard;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceDefinition;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceContext;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferencePolicy;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceResolver;
use Kumwe\CMS\Presentation\Application\Preference\RegisteredPresentationPreferencePolicy;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Tests\Support\InMemoryPresentationPreferenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies hierarchy precedence, compatibility fallback, and live registry admission.
 *
 * @since  2.0.0
 */
#[CoversClass(PresentationPreferenceContext::class)]
#[CoversClass(PresentationPreferenceResolver::class)]
#[CoversClass(RegisteredPresentationPreferencePolicy::class)]
final class PresentationPreferenceResolverTest extends TestCase
{
    /**
     * Proves administrator resolution applies site, administrator, role/workspace, then user values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorHierarchyResolvesLowToHighPrecedence(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.settings');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::human(
            [],
            membership: AuthorizationContext::membership(workspace: 'workspace:operations'),
        );
        foreach (
            [
            [CustomizationScope::Site, 'default', 'comfortable'],
            [CustomizationScope::Administrator, null, 'compact'],
            [CustomizationScope::RoleWorkspace, 'workspace:operations', 'touch'],
            [CustomizationScope::User, $context->actorId(), 'comfortable'],
            ] as $index => [$scope, $scopeId, $value]
        ) {
            $repository->seed(PresentationPreference::create(
                $surface,
                $owner,
                $scope,
                $scopeId,
                CustomizationSlot::Density,
                $value,
                $index + 1,
                'actor:administrator',
                new DateTimeImmutable('2026-08-11T12:00:00Z'),
            ));
        }
        $resolver = new PresentationPreferenceResolver($repository, new AllowAllPresentationPreferencePolicy());

        $resolution = $resolver->resolve(
            $surface,
            $owner,
            CustomizationSlot::Density,
            'comfortable',
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Administrator, $context),
        );

        self::assertSame('comfortable', $resolution->value->value());
        self::assertSame(CustomizationScope::User, $resolution->source);
        self::assertSame(4, $resolution->version);
        self::assertTrue($resolution->customized());
    }

    /**
     * Proves portal resolution never consumes installation administrator defaults.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalHierarchyExcludesAdministratorLayer(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.portal.home');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::human([]);
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::Administrator,
            null,
            CustomizationSlot::Density,
            'compact',
            1,
            'actor:administrator',
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $resolver = new PresentationPreferenceResolver($repository, new AllowAllPresentationPreferencePolicy());

        $resolution = $resolver->resolve(
            $surface,
            $owner,
            CustomizationSlot::Density,
            'comfortable',
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Portal, $context),
        );

        self::assertSame('comfortable', $resolution->value->value());
        self::assertNull($resolution->source);
    }

    /**
     * Proves an unattended execution identity never becomes an authenticated user preference layer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSystemContextDoesNotResolveUserLayer(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.settings');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::system(SystemIdentity::Worker)->context(
            SiteContext::default(),
            'preference-system-resolution-test',
        );
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::Density,
            'touch',
            1,
            $context->actorId(),
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $resolver = new PresentationPreferenceResolver($repository, new AllowAllPresentationPreferencePolicy());

        $resolution = $resolver->resolve(
            $surface,
            $owner,
            CustomizationSlot::Density,
            'comfortable',
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Administrator, $context),
        );

        self::assertSame('comfortable', $resolution->value->value());
        self::assertNull($resolution->source);
    }

    /**
     * Proves a removed higher-layer slot yields a diagnostic and retains the lower safe value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRemovedSlotFallsBackWithDiagnostic(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.settings');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::human([]);
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::Site,
            'default',
            CustomizationSlot::Density,
            'compact',
            1,
            'actor:administrator',
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::Density,
            'touch',
            2,
            $context->actorId(),
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $policy = new SelectivePresentationPreferencePolicy([CustomizationScope::Site]);
        $resolver = new PresentationPreferenceResolver($repository, $policy);

        $resolution = $resolver->resolve(
            $surface,
            $owner,
            CustomizationSlot::Density,
            'comfortable',
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Administrator, $context),
        );

        self::assertSame('compact', $resolution->value->value());
        self::assertSame(CustomizationScope::Site, $resolution->source);
        self::assertSame(['kis.preference.slot-removed'], $resolution->diagnostics);
    }

    /**
     * Proves the production policy reads exact owner, slot, and scope permission from the live registry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRegisteredPolicyRequiresExactLiveCustomizationDeclaration(): void
    {
        $owner = ContributionOwner::core();
        $surfaces = new OwnedRuntimeContributionRegistry('interface surface');
        $surface = SurfaceDefinition::fromArray($owner, $this->surface());
        $surfaces->register($owner, $surface);
        $policy = new RegisteredPresentationPreferencePolicy($surfaces);

        self::assertTrue($policy->allows(
            SurfaceId::fromString('core.administrator.settings'),
            $owner,
            CustomizationSlot::Density,
            CustomizationScope::User,
        ));
        self::assertFalse($policy->allows(
            SurfaceId::fromString('core.administrator.settings'),
            $owner,
            CustomizationSlot::Density,
            CustomizationScope::Administrator,
        ));
        $this->expectException(InvalidArgumentException::class);
        $policy->assertAllowed(
            SurfaceId::fromString('core.administrator.settings'),
            $owner,
            CustomizationSlot::Columns,
            CustomizationScope::User,
        );
    }

    /**
     * Return one conformant surface with a single customization permission.
     *
     * @return  array<string, mixed>
     *
     * @since   2.0.0
     */
    private function surface(): array
    {
        return [
            'surface' => 'core.administrator.settings',
            'standard' => 'kis-1.0',
            'area' => 'administrator',
            'actor' => 'administrator',
            'intent' => 'configure',
            'resource' => 'site-settings',
            'purpose' => 'Manage approved presentation settings for the current site.',
            'pattern' => 'settings-workspace',
            'capabilities' => ['settings.manage'],
            'states' => ['default', 'error', 'permission-reduced', 'read-only'],
            'customization' => [['slot' => 'density', 'scope' => 'user']],
            'responsive' => [[
                'element' => 'settings-form',
                'priority' => 'essential',
                'may_collapse' => false,
            ]],
            'icon' => 'settings',
        ];
    }
}

/**
 * Test policy admitting every slot and scope pair.
 *
 * @since  2.0.0
 */
final readonly class AllowAllPresentationPreferencePolicy implements PresentationPreferencePolicy
{
    /** @inheritDoc */
    public function allows(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): bool {
        return true;
    }

    /** @inheritDoc */
    public function assertAllowed(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): void {
    }
}

/**
 * Test policy admitting only nominated hierarchy layers.
 *
 * @since  2.0.0
 */
final readonly class SelectivePresentationPreferencePolicy implements PresentationPreferencePolicy
{
    /**
     * @param  list<CustomizationScope>  $scopes  Layers admitted by this test policy.
     *
     * @since  2.0.0
     */
    public function __construct(private array $scopes)
    {
    }

    /** @inheritDoc */
    public function allows(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): bool {
        return in_array($scope, $this->scopes, true);
    }

    /** @inheritDoc */
    public function assertAllowed(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): void {
        if (!$this->allows($surface, $owner, $slot, $scope)) {
            throw new InvalidArgumentException('Not allowed by the selective test policy.');
        }
    }
}
