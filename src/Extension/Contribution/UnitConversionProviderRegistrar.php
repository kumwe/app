<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Conversion\Provider\UnitConversionProvider;

/**
 * Additive capability for providers that supply unit-of-measure conversion factors.
 *
 * This interface is separate from the frozen contribution SPI-2 registrar so existing providers remain
 * source compatible. A package with `integration.unit_converters` requires this capability explicitly
 * before it reconciles those declarations; the concrete owner-bound registrar implements it alongside
 * the others.
 *
 * It exists because core owns the unit conversion contract but ships no conversion table of any kind.
 * This is the whole of the route by which a factor reaches a Kumwe installation.
 *
 * @since  2.0.0
 */
interface UnitConversionProviderRegistrar
{
    /**
     * Publish one manifest-reconciled conversion provider and the implementation that answers for it.
     *
     * @param   UnitConversionProviderDefinition  $definition  Owner-bound declaration naming the units it relates.
     * @param   UnitConversionProvider            $provider    Implementation whose identity matches that
     *          declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function unitConversionProvider(
        UnitConversionProviderDefinition $definition,
        UnitConversionProvider $provider,
    ): void;
}
