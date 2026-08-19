<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Integration;

use Kumwe\App\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\App\BusinessIntegration\Domain\DomainEvent;
use Kumwe\App\BusinessIntegration\Domain\DomainListenerDefinition;

/**
 * Performs transaction-local validation of inspection mutation metadata without external side effects.
 *
 * @since  2.0.0
 */
final readonly class InspectionMutationListener implements DomainEventHandler
{
    /**
     * Bind the executable listener to its signed declaration and bounded diagnostics.
     *
     * @param  DomainListenerDefinition  $definition  Exact listener declaration.
     * @param  IntegrationLedger         $ledger      Process-local, non-authoritative evidence sink.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DomainListenerDefinition $definition,
        private IntegrationLedger $ledger,
    ) {
    }

    /**
     * Return the exact signed listener contract implemented here.
     *
     * @return  DomainListenerDefinition  Core record mutation schema-one declaration.
     *
     * @since   2.0.0
     */
    public function definition(): DomainListenerDefinition
    {
        return $this->definition;
    }

    /**
     * Validate each routed core mutation and retain bounded evidence only for this inspection definition.
     *
     * @param   DomainEvent  $event  Mutation event executing inside the authoritative transaction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(DomainEvent $event): void
    {
        if (InspectionMutation::belongsToInspection($event)) {
            $this->ledger->recordDomain($event);
        }
    }
}
