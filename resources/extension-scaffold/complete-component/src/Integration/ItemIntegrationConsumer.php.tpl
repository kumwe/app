<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Integration;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;

/**
 * Performs an idempotent process-local observation of durable item events.
 *
 * @since  2.0.0
 */
final readonly class ItemIntegrationConsumer implements IntegrationEventHandler
{
    /**
     * Bind the executable consumer to its signed declaration and diagnostic ledger.
     *
     * @param  EventConsumerDefinition  $definition  Exact signed durable-consumer contract.
     * @param  IntegrationLedger        $ledger      Bounded diagnostic event ledger.
     *
     * @since  2.0.0
     */
    public function __construct(
        private EventConsumerDefinition $definition,
        private IntegrationLedger $ledger,
    ) {
    }

    /**
     * Return the exact signed contract implemented by this consumer.
     *
     * @return  EventConsumerDefinition  Immutable consumer declaration.
     *
     * @since   2.0.0
     */
    public function definition(): EventConsumerDefinition
    {
        return $this->definition;
    }

    /**
     * Validate direct invocations and record the inbox-deduplicated event identity.
     *
     * @param   IntegrationEvent  $event    Durable item-observed event.
     * @param   ExecutionContext  $context  Worker-owned execution context.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the event falls outside the signed consumer contract.
     *
     * @since   2.0.0
     */
    public function handle(IntegrationEvent $event, ExecutionContext $context): void
    {
        if (
            $event->eventType() !== '@@EXTENSION_DOTTED@@.item_observed'
            || !$this->definition->acceptsVersion($event->schemaVersion())
            || !$event->sensitivity()->allowedBy($this->definition->sensitivityCeiling())
        ) {
            throw new InvalidArgumentException('The item consumer received an unsupported event contract.');
        }
        $this->ledger->recordIntegration($event);
    }
}
