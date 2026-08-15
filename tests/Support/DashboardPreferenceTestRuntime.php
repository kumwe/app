<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\MembershipContext;
use Kumwe\CMS\Application\Authorization\MembershipContextValidator;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceService;
use Kumwe\CMS\Presentation\Application\Preference\PresentationAccessGroup;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceManager;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferencePolicy;
use Psr\Clock\ClockInterface;

/**
 * Wires the real dashboard preference service over deterministic in-memory test boundaries.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceTestRuntime
{
    /**
     * Preference rows written or seeded by the scenario.
     *
     * @var    InMemoryPresentationPreferenceRepository
     * @since  2.0.0
     */
    public InMemoryPresentationPreferenceRepository $preferences;

    /**
     * Canonical access-group projection used by manager authorization.
     *
     * @var    InMemoryPresentationAccessGroupRepository
     * @since  2.0.0
     */
    public InMemoryPresentationAccessGroupRepository $groups;

    /**
     * Real application service under test.
     *
     * @var    DashboardPreferenceService
     * @since  2.0.0
     */
    public DashboardPreferenceService $service;

    /**
     * Build the runtime with optional stable role projections.
     *
     * @param  list<PresentationAccessGroup>  $groups  Live access groups visible to preference delivery.
     *
     * @since  2.0.0
     */
    public function __construct(array $groups = [])
    {
        $this->preferences = new InMemoryPresentationPreferenceRepository();
        $this->groups = new InMemoryPresentationAccessGroupRepository($groups);
        $memberships = new DashboardPreferenceCurrentMemberships();
        $manager = new PresentationPreferenceManager(
            $this->preferences,
            new DashboardPreferenceAllowAllPolicy(),
            AuthorizationContext::gateway(memberships: $memberships),
            new DashboardPreferenceNullAuditRecorder(),
            new DashboardPreferenceFixedClock(),
            new ImmediateTransactionManager(),
            $memberships,
            $this->groups,
        );
        $this->service = new DashboardPreferenceService($manager, $this->groups);
    }
}

/**
 * Admits every dashboard test surface while production authorization remains active in the manager.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceAllowAllPolicy implements PresentationPreferencePolicy
{
    /**
     * Admit every surface tuple supplied by a focused dashboard service test.
     *
     * @param   SurfaceId           $surface  Test dashboard surface.
     * @param   ContributionOwner   $owner    Test contribution owner.
     * @param   CustomizationSlot   $slot     Test dashboard slot.
     * @param   CustomizationScope  $scope    Test hierarchy layer.
     *
     * @return  bool  Always true.
     *
     * @since   2.0.0
     */
    public function allows(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): bool {
        return true;
    }

    /**
     * Accept every surface tuple supplied by a focused dashboard service test.
     *
     * @param   SurfaceId           $surface  Test dashboard surface.
     * @param   ContributionOwner   $owner    Test contribution owner.
     * @param   CustomizationSlot   $slot     Test dashboard slot.
     * @param   CustomizationScope  $scope    Test hierarchy layer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): void {
    }
}

/**
 * Accepts membership snapshots so unrelated workspace checks never obscure dashboard service scenarios.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceCurrentMemberships implements MembershipContextValidator
{
    /**
     * Treat each supplied membership snapshot as current for unrelated workspace test paths.
     *
     * @param   string             $subjectId   Actor owning the membership.
     * @param   SiteContext        $site        Exact site being evaluated.
     * @param   MembershipContext  $membership  Supplied membership snapshot.
     * @param   bool               $lock        Whether a mutation requested a pessimistic read.
     *
     * @return  bool  Always true.
     *
     * @since   2.0.0
     */
    public function current(
        string $subjectId,
        SiteContext $site,
        MembershipContext $membership,
        bool $lock = false,
    ): bool {
        return true;
    }
}

/**
 * Discards audit events after the manager proves it reached the canonical recorder boundary.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceNullAuditRecorder implements AuditRecorder
{
    /**
     * Accept a manager audit event without retaining irrelevant mutable fixture state.
     *
     * @param   AuditEvent  $event  Validated preference mutation event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuditEvent $event): void
    {
    }
}

/**
 * Supplies a deterministic mutation instant.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceFixedClock implements ClockInterface
{
    /**
     * Return the fixed dashboard preference mutation instant.
     *
     * @return  DateTimeImmutable  Stable test time.
     *
     * @since   2.0.0
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    }
}
