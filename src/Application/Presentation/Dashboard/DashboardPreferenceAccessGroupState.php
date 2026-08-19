<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Presentation\Dashboard;

use InvalidArgumentException;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\PresentationPreference;
use Kumwe\App\InterfaceStandard\SurfaceId;

/**
 * Exact stored dashboard choices for one authorized canonical access group.
 *
 * Missing rows remain null so the presentation layer can distinguish inheritance from an intentional empty list.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceAccessGroupState
{
    /**
     * Validate two optional preference rows against their canonical role and dashboard surface.
     *
     * @param   SurfaceId                $surface    Dashboard surface whose state was queried.
     * @param   PresentationAccessGroup  $group      Authorized canonical role projection.
     * @param   ?PresentationPreference  $widgets    Exact dashboard-card row, or null when inherited.
     * @param   ?PresentationPreference  $shortcuts  Exact navigation-shortcut row, or null when inherited.
     *
     * @throws  InvalidArgumentException  When a supplied row belongs to another surface, scope, role, or slot.
     *
     * @since   2.0.0
     */
    public function __construct(
        public SurfaceId $surface,
        public PresentationAccessGroup $group,
        public ?PresentationPreference $widgets,
        public ?PresentationPreference $shortcuts,
    ) {
        self::assertPreference($widgets, $surface, $group->id, CustomizationSlot::DashboardCards);
        self::assertPreference($shortcuts, $surface, $group->id, CustomizationSlot::NavigationShortcuts);
    }

    /**
     * Require an optional row to be the exact role-scoped slot represented by this state.
     *
     * @param   ?PresentationPreference  $preference  Candidate stored row, or null for inheritance.
     * @param   SurfaceId                $surface     Dashboard surface queried by the use case.
     * @param   string                   $scopeId     Canonical `role:<uuid>` preference identity.
     * @param   CustomizationSlot        $slot        Expected list slot.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a supplied row does not match the exact state identity.
     *
     * @since   2.0.0
     */
    private static function assertPreference(
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
            || $preference->scope() !== CustomizationScope::RoleWorkspace
            || $preference->scopeId() !== $scopeId
            || $preference->slot() !== $slot
        ) {
            throw new InvalidArgumentException('A dashboard access-group preference state row is inconsistent.');
        }
    }
}
