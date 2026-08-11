<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

/**
 * One whitelisted presentation slot and the layer allowed to customize it.
 *
 * @since  2.0.0
 */
final readonly class CustomizationPermission
{
    /**
     * Pair an approved presentation choice with its allowed configuration layer.
     *
     * Semantic compatibility between a slot and scope is checked by `SurfaceConformanceValidator`, so
     * invalid pairs produce a structured diagnostic before a contribution can be admitted.
     *
     * @param  CustomizationSlot   $slot   Safe presentation choice exposed by the surface.
     * @param  CustomizationScope  $scope  Configuration layer permitted to change that choice.
     *
     * @since  2.0.0
     */
    public function __construct(public CustomizationSlot $slot, public CustomizationScope $scope)
    {
    }

    /**
     * Export the manifest-comparison shape.
     *
     * @return  array{slot: string, scope: string}  Canonical slot and scope values.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['slot' => $this->slot->value, 'scope' => $this->scope->value];
    }
}
