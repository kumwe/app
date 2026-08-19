<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\App\BusinessRecord\Domain\UnitConversionRequest;

/**
 * The conversion providers entitled to answer one request, in the order they are consulted.
 *
 * The pipeline is a rule about how a factor is chosen and applied; which candidates exist is a
 * composition concern, which is why it arrives through this port instead. The catalog also applies the
 * bound a package accepted at install — a provider that did not declare both units is not offered the
 * conversion at all — so a package cannot widen its reach by changing its runtime behaviour after
 * admission.
 *
 * Core's own implementation reads the extension contribution registry and therefore returns nothing in
 * an installation with no conversion package installed, which is the intended default.
 *
 * @since  2.0.0
 */
interface UnitConversionProviderCatalog
{
    /**
     * List the providers currently entitled to answer one conversion.
     *
     * @param   UnitConversionRequest  $request  Conversion a caller is looking for a factor for.
     *
     * @return  list<UnitConversionProvider>  Entitled providers in resolution order; empty when none is
     *          contributed or none declared both units.
     *
     * @since   2.0.0
     */
    public function providersFor(UnitConversionRequest $request): array;
}
