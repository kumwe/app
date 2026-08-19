<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\App\BusinessRecord\Domain\MoneyConversionRequest;

/**
 * The rate providers entitled to answer one conversion, in the order they are consulted.
 *
 * The pipeline is a rule about how a rate is chosen and applied; which candidates exist is a
 * composition concern, which is why it arrives through this port instead. The catalog also applies the
 * bound a package accepted at install — a provider that did not declare both currencies is not offered
 * the conversion at all — so a package cannot widen its reach by changing its runtime behaviour after
 * admission.
 *
 * Core's own implementation reads the extension contribution registry and therefore returns nothing in
 * an installation with no rate package installed, which is the intended default.
 *
 * @since  2.0.0
 */
interface MoneyRateProviderCatalog
{
    /**
     * List the providers currently entitled to answer one conversion.
     *
     * @param   MoneyConversionRequest  $request  Conversion a caller is looking for a rate for.
     *
     * @return  list<MoneyRateProvider>  Entitled providers in resolution order; empty when none is contributed
     *          or none declared both currencies.
     *
     * @since   2.0.0
     */
    public function providersFor(MoneyConversionRequest $request): array;
}
