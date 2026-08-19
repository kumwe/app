<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Presentation\Dashboard;

use InvalidArgumentException;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\PresentationPreference;
use Kumwe\App\InterfaceStandard\SurfaceId;

/**
 * Bounded authorized dashboard preference state returned by the application query use case.
 *
 * The value contains no form fields, message identifiers, or HTTP protocol details. Presentation decides how
 * inherited rows are displayed, while explicit paging state keeps every role reachable through bounded reads.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceState
{
    /**
     * Validate one user's exact rows and the authorized bounded access-group state.
     *
     * @param   SurfaceId                                  $surface                    Queried dashboard surface.
     * @param   string                                     $userScopeId                Authenticated actor identity.
     * @param   ?PresentationPreference                    $personalWidgets            Exact personal card row.
     * @param   ?PresentationPreference                    $personalShortcuts          Exact personal shortcut row.
     * @param   list<DashboardPreferenceAccessGroupState>  $accessGroups               Manageable canonical groups.
     * @param bool $accessGroupAdministration Whether group management is authorized.
     * @param   DashboardPreferenceQuery                   $accessGroupQuery           Validated page and search.
     * @param bool $accessGroupHasPrevious Whether a previous page is reachable.
     * @param bool $accessGroupHasNext Whether a later page is reachable.
     * @param bool $accessGroupBrowseLimit Whether targeted search is required.
     *
     * @throws  InvalidArgumentException  When rows or the bounded group collection are inconsistent.
     *
     * @since   2.0.0
     */
    public function __construct(
        public SurfaceId $surface,
        public string $userScopeId,
        public ?PresentationPreference $personalWidgets,
        public ?PresentationPreference $personalShortcuts,
        public array $accessGroups,
        public bool $accessGroupAdministration,
        public DashboardPreferenceQuery $accessGroupQuery,
        public bool $accessGroupHasPrevious,
        public bool $accessGroupHasNext,
        public bool $accessGroupBrowseLimit,
    ) {
        if ($userScopeId === '' || strlen($userScopeId) > 191) {
            throw new InvalidArgumentException('A dashboard preference state user identity is invalid.');
        }
        self::assertPersonal($personalWidgets, $surface, $userScopeId, CustomizationSlot::DashboardCards);
        self::assertPersonal(
            $personalShortcuts,
            $surface,
            $userScopeId,
            CustomizationSlot::NavigationShortcuts,
        );
        if (
            !array_is_list($accessGroups)
            || count($accessGroups) > DashboardPreferenceService::ACCESS_GROUP_PAGE_SIZE
        ) {
            throw new InvalidArgumentException('Dashboard preference access-group state must be a bounded list.');
        }
        if (
            !$accessGroupAdministration
            && ($accessGroups !== [] || $accessGroupHasPrevious || $accessGroupHasNext || $accessGroupBrowseLimit)
        ) {
            throw new InvalidArgumentException('Dashboard preference access-group paging is not authorized.');
        }
        if ($accessGroupHasPrevious !== ($accessGroupAdministration && $accessGroupQuery->page > 1)) {
            throw new InvalidArgumentException('Dashboard preference previous-page state is inconsistent.');
        }
        if (
            $accessGroupBrowseLimit
            && ($accessGroupQuery->page !== DashboardPreferenceQuery::MAXIMUM_PAGE || $accessGroupHasNext)
        ) {
            throw new InvalidArgumentException('Dashboard preference browse-limit state is inconsistent.');
        }
        $seen = [];
        $previous = null;
        foreach ($accessGroups as $state) {
            if (!$state instanceof DashboardPreferenceAccessGroupState || isset($seen[$state->group->id])) {
                throw new InvalidArgumentException('Dashboard preference access-group state is invalid.');
            }
            if ($state->surface->value() !== $surface->value()) {
                throw new InvalidArgumentException('Dashboard preference access-group state uses another surface.');
            }
            $order = [$state->group->code, $state->group->roleId];
            if ($previous !== null && $previous > $order) {
                throw new InvalidArgumentException('Dashboard preference access-group state is not ordered.');
            }
            $seen[$state->group->id] = true;
            $previous = $order;
        }
    }

    /**
     * Require an optional row to be the exact user-scoped slot represented by this state.
     *
     * @param   ?PresentationPreference  $preference  Candidate stored row, or null for inheritance.
     * @param   SurfaceId                $surface     Dashboard surface queried by the use case.
     * @param   string                   $scopeId     Authenticated actor identity.
     * @param   CustomizationSlot        $slot        Expected list slot.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a supplied row does not match the exact state identity.
     *
     * @since   2.0.0
     */
    private static function assertPersonal(
        ?PresentationPreference $preference,
        SurfaceId $surface,
        string $scopeId,
        CustomizationSlot $slot,
    ): void {
        if ($preference === null) {
            return;
        }
        if (
            $preference->surface()->value() !== $surface->value()
            || $preference->scope() !== CustomizationScope::User
            || $preference->scopeId() !== $scopeId
            || $preference->slot() !== $slot
        ) {
            throw new InvalidArgumentException('A personal dashboard preference state row is inconsistent.');
        }
    }
}
