<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\App\BusinessRecord\Application\MoneyRateProvider;
use Kumwe\App\BusinessRecord\Domain\MoneyRateProviderDefinition;

/**
 * Additive capability for providers that supply exchange rates.
 *
 * This interface is separate from the frozen contribution SPI-2 registrar so existing providers remain
 * source compatible. A package with `integration.rate_providers` requires this capability explicitly
 * before it reconciles those declarations; the concrete owner-bound registrar implements it alongside
 * the others.
 *
 * It exists because core owns the money conversion contract but ships no rate of any kind. This is the
 * whole of the route by which a rate reaches a Kumwe installation.
 *
 * @since  2.0.0
 */
interface MoneyRateProviderRegistrar
{
    /**
     * Publish one manifest-reconciled rate provider and the implementation that answers for it.
     *
     * @param   MoneyRateProviderDefinition  $definition  Owner-bound declaration naming the currencies it prices.
     * @param   MoneyRateProvider            $provider    Implementation whose identity matches that declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function moneyRateProvider(
        MoneyRateProviderDefinition $definition,
        MoneyRateProvider $provider,
    ): void;
}
