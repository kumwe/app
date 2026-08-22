<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure;

use Kumwe\App\BusinessRecord\Application\MoneyRateProvider;
use Kumwe\App\BusinessRecord\Application\MoneyRateProviderCatalog;
use Kumwe\App\BusinessRecord\Domain\MoneyConversionRequest;
use Kumwe\App\BusinessRecord\Domain\MoneyRateProviderDefinition;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;

/**
 * The active rate providers, read from the extension contributions of the running generation.
 *
 * This is the only place a rate provider becomes reachable, and it reads the same registry that
 * disable, uninstall and trust revocation sweep. A package that has been withdrawn therefore stops
 * pricing conversions in the same instant it stops serving anything else, without a second list to keep
 * in step.
 *
 * Order is the packages' declared priority and then their identifier, so the provider that answers a
 * given conversion is the same one on every process and every request rather than whichever happened to
 * register first.
 *
 * @since  2.0.0
 */
final readonly class RuntimeMoneyRateProviderCatalog implements MoneyRateProviderCatalog
{
    /**
     * Read providers from the contribution registries the running generation published.
     *
     * @param  ExtensionContributionRegistrySet  $contributions  Active owner-aware contribution registries.
     * @param  ExtensionExecutionGate             $execution      Live authority for resident provider objects.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionContributionRegistrySet $contributions,
        private ExtensionExecutionGate $execution,
    ) {
    }

    /**
     * List the contributed providers that declared this conversion's currencies, in resolution order.
     *
     * @param   MoneyConversionRequest  $request  Conversion a caller is looking for a rate for.
     *
     * @return  list<MoneyRateProvider>  Entitled providers, lowest declared priority first; empty in an
     *          installation with no rate package, which is core's own shipped state.
     *
     * @since   2.0.0
     */
    public function providersFor(MoneyConversionRequest $request): array
    {
        $entries = $this->contributions->moneyRateProviders()->executableEntries();
        if ($entries !== []) {
            $this->execution->assertCurrent();
        }
        $ordered = [];
        foreach ($entries as $entry) {
            $definition = $entry['definition'];
            $implementation = $entry['implementation'];
            if (
                !$definition instanceof MoneyRateProviderDefinition
                || !$implementation instanceof MoneyRateProvider
                || !$definition->prices($request)
            ) {
                continue;
            }
            $ordered[] = [
                'priority' => $definition->priority(),
                'identifier' => $definition->identifier(),
                'provider' => $implementation,
            ];
        }
        usort($ordered, static fn (array $left, array $right): int => [
            $left['priority'],
            $left['identifier'],
        ] <=> [
            $right['priority'],
            $right['identifier'],
        ]);
        $providers = [];
        foreach ($ordered as $entry) {
            $providers[] = $entry['provider'];
        }

        return $providers;
    }
}
