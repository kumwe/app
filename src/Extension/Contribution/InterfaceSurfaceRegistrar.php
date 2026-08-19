<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\App\InterfaceStandard\SurfaceDefinition;

/**
 * Additive capability for providers that declare KIS semantic surfaces.
 *
 * This interface is separate from the frozen contribution SPI-2 registrar so existing providers remain
 * source compatible. A package with `interface.surfaces` requires this capability explicitly before it
 * reconciles those declarations; the concrete owner-bound registrar implements both contracts.
 *
 * @since  2.0.0
 */
interface InterfaceSurfaceRegistrar
{
    /**
     * Publish one manifest-reconciled semantic interface surface.
     *
     * The declaration contains no renderer or policy implementation. It records the task, pattern,
     * states, responsive priorities, and allowed customization after application policy has filtered
     * the actual route state.
     *
     * @param   SurfaceDefinition  $definition  Owner-bound declaration already admitted by KIS.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function interfaceSurface(SurfaceDefinition $definition): void;
}
