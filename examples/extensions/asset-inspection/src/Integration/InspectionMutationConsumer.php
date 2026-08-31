<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Integration;

use InvalidArgumentException;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;

/**
 * Handles durable, inbox-deduplicated inspection mutation events under the worker's site context.
 *
 * @since  2.0.0
 */
final readonly class InspectionMutationConsumer implements IntegrationEventHandler
{
    /**
     * Bind the executable consumer to bounded diagnostics.
     *
     * @param  IntegrationLedger  $ledger  Process-local, non-authoritative evidence sink.
     *
     * @since  2.0.0
     */
    public function __construct(private IntegrationLedger $ledger)
    {
    }

    /**
     * Validate worker scope and record only this component's inbox-processed inspection mutations.
     *
     * @param   EventConsumerDefinition  $definition  Host-validated exact signed durable-consumer declaration.
     * @param   IntegrationEvent         $event       Durable core record mutation event.
     * @param   ExecutionContext         $context     Fresh worker-owned site context.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the worker context and immutable event site disagree.
     *
     * @since   2.0.0
     */
    public function handle(
        EventConsumerDefinition $definition,
        IntegrationEvent $event,
        ExecutionContext $context,
    ): void {
        if ($definition->identifier() !== 'kumwe.asset-inspection-example.inspection-mutation-indexer') {
            throw new InvalidArgumentException('The inspection consumer received a foreign declaration.');
        }
        if (
            $definition->eventType() !== $event->eventType()
            || !$definition->acceptsVersion($event->schemaVersion())
            || !$event->sensitivity()->allowedBy($definition->sensitivityCeiling())
        ) {
            throw new InvalidArgumentException('The inspection consumer requires its declared event contract.');
        }
        if ($context->siteIdentifier() !== $event->siteIdentifier()) {
            throw new InvalidArgumentException('The inspection consumer requires the event-owning site context.');
        }
        if (InspectionMutation::belongsToInspection($event)) {
            $this->ledger->recordIntegration($event);
        }
    }
}
