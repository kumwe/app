<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Presentation\Preference;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceConformanceValidator;
use Kumwe\CMS\InterfaceStandard\SurfaceDefinition;
use Kumwe\CMS\InterfaceStandard\SurfaceId;

/**
 * Resolves customization admission from the live owner-bound KIS surface registry.
 *
 * @since  2.0.0
 */
final readonly class RegisteredPresentationPreferencePolicy implements PresentationPreferencePolicy
{
    /**
     * Bind preference admission to the same registry extension activation and trust lifecycle controls.
     *
     * @param  OwnedRuntimeContributionRegistry  $surfaces  Live declarative KIS surface contributions.
     *
     * @since  2.0.0
     */
    public function __construct(private OwnedRuntimeContributionRegistry $surfaces)
    {
    }

    /**
     * Determine whether the current owner and surface expose a slot at or below its declared ceiling.
     *
     * @param   SurfaceId           $surface  Current semantic surface.
     * @param   ContributionOwner   $owner    Expected active owner.
     * @param   CustomizationSlot   $slot     Presentation choice being considered.
     * @param   CustomizationScope  $scope    Hierarchy layer being considered.
     *
     * @return  bool  False for an absent, foreign, stale, or no-longer-exposed declaration.
     *
     * @since   2.0.0
     */
    public function allows(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): bool {
        $definition = $this->surfaces->definition($owner, $surface->value());
        if (!$definition instanceof SurfaceDefinition) {
            return false;
        }
        foreach ($definition->declaration->customization as $permission) {
            if (
                $permission->slot === $slot
                && self::areaAllows($definition->declaration->area, $scope)
                && SurfaceConformanceValidator::allowsCustomizationAtOrBelow(
                    $slot,
                    $permission->scope,
                    $scope,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep a portable ceiling from introducing a hierarchy layer the rendered area never consumes.
     *
     * @param   SurfaceArea         $area   Actor-facing area declared by the live surface.
     * @param   CustomizationScope  $scope  Candidate hierarchy layer.
     *
     * @return  bool  True when the area can resolve the candidate layer.
     *
     * @since   2.0.0
     */
    private static function areaAllows(SurfaceArea $area, CustomizationScope $scope): bool
    {
        return match ($area) {
            SurfaceArea::Administrator => true,
            SurfaceArea::Portal => $scope !== CustomizationScope::Administrator,
            SurfaceArea::Public => !in_array(
                $scope,
                [CustomizationScope::Administrator, CustomizationScope::RoleWorkspace],
                true,
            ),
            SurfaceArea::Template => false,
        };
    }

    /**
     * Require the live registry to admit a mutation's slot at or below its declared ceiling.
     *
     * @param   SurfaceId           $surface  Current semantic surface.
     * @param   ContributionOwner   $owner    Expected active owner.
     * @param   CustomizationSlot   $slot     Presentation choice being mutated.
     * @param   CustomizationScope  $scope    Hierarchy layer being mutated.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the live owner-bound declaration does not admit the pair.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): void {
        if (!$this->allows($surface, $owner, $slot, $scope)) {
            throw new InvalidArgumentException(sprintf(
                'The active KIS surface does not allow %s customization at the %s scope.',
                $slot->value,
                $scope->value,
            ));
        }
    }
}
