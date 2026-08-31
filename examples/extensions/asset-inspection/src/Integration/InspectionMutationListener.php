<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Integration;

use InvalidArgumentException;
use Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDefinition;

/**
 * Performs transaction-local validation of inspection mutation metadata without external side effects.
 *
 * @since  2.0.0
 */
final readonly class InspectionMutationListener implements DomainEventHandler
{
    /**
     * Bind the executable listener to bounded diagnostics.
     *
     * @param  IntegrationLedger  $ledger  Process-local, non-authoritative evidence sink.
     *
     * @since  2.0.0
     */
    public function __construct(private IntegrationLedger $ledger)
    {
    }

    /**
     * Validate each routed core mutation and retain bounded evidence only for this inspection definition.
     *
     * @param   DomainListenerDefinition  $definition  Host-validated exact signed listener declaration.
     * @param   DomainEvent               $event       Mutation event executing inside the authoritative transaction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(DomainListenerDefinition $definition, DomainEvent $event): void
    {
        if ($definition->identifier() !== 'kumwe.asset-inspection-example.inspection-mutation-validator') {
            throw new InvalidArgumentException('The inspection listener received a foreign declaration.');
        }
        if (!$definition->accepts($event->eventType(), $event->schemaVersion(), $event->sensitivity())) {
            throw new InvalidArgumentException('The inspection listener requires its declared event contract.');
        }
        if (InspectionMutation::belongsToInspection($event)) {
            $this->ledger->recordDomain($event);
        }
    }
}
