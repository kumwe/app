<?php

declare(strict_types=1);

namespace KumweContract\ManifestFour\Integration;

use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\App\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\IntegrationEvent;

/**
 * Durable, queue-backed consumer half of the manifest-4 compatibility package.
 *
 * @since  2.0.0
 */
final readonly class ObservationConsumer implements IntegrationEventHandler
{
    /**
     * Bind the executable consumer to the declaration the manifest signed.
     *
     * @param  EventConsumerDefinition  $definition  Exact consumer declaration.
     * @param  ObservationLedger        $ledger      Process-local evidence sink.
     *
     * @since  2.0.0
     */
    public function __construct(
        private EventConsumerDefinition $definition,
        private ObservationLedger $ledger,
    ) {
    }

    /**
     * Return the signed consumer contract implemented here.
     *
     * @return  EventConsumerDefinition  The declaration handed in at construction.
     *
     * @since   2.0.0
     */
    public function definition(): EventConsumerDefinition
    {
        return $this->definition;
    }

    /**
     * Record that the durable consumer ran, without any external effect.
     *
     * @param   IntegrationEvent  $event    Delivered event, already checked against its schema.
     * @param   ExecutionContext  $context  Authorization context the worker built for the event's owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(IntegrationEvent $event, ExecutionContext $context): void
    {
        $this->ledger->record('consumer');
    }
}
