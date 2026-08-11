<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Preference;

use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\SurfaceId;

/**
 * Live surface-admission boundary for stored presentation preferences.
 *
 * A schema-valid record is not automatically active: its owner must still contribute the surface and
 * that declaration must still expose the exact slot and scope. Mutations require `assertAllowed()`;
 * resolution uses `allows()` so a removed slot falls back safely instead of breaking rendering.
 *
 * @since  2.0.0
 */
interface PresentationPreferencePolicy
{
    /**
     * Determine whether the current owner-bound surface admits an exact customization pair.
     *
     * @param   SurfaceId           $surface  Current semantic surface.
     * @param   ContributionOwner   $owner    Expected active owner.
     * @param   CustomizationSlot   $slot     Presentation choice being considered.
     * @param   CustomizationScope  $scope    Hierarchy layer being considered.
     *
     * @return  bool  True only when the live admitted declaration contains the pair.
     *
     * @since   2.0.0
     */
    public function allows(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): bool;

    /**
     * Require a live owner-bound declaration to expose an exact customization pair.
     *
     * @param   SurfaceId           $surface  Current semantic surface.
     * @param   ContributionOwner   $owner    Expected active owner.
     * @param   CustomizationSlot   $slot     Presentation choice being mutated.
     * @param   CustomizationScope  $scope    Hierarchy layer being mutated.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When ownership, surface activity, slot, or scope no longer matches.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): void;
}
