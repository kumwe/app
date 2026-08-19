<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Integration;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\App\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\IntegrationEvent;

/**
 * Handles durable, inbox-deduplicated inspection mutation events under the worker's site context.
 *
 * @since  2.0.0
 */
final readonly class InspectionMutationConsumer implements IntegrationEventHandler
{
    /**
     * Bind the executable consumer to its aggregate-ordered declaration and bounded diagnostics.
     *
     * @param  EventConsumerDefinition  $definition  Exact signed durable-consumer contract.
     * @param  IntegrationLedger        $ledger      Process-local, non-authoritative evidence sink.
     *
     * @since  2.0.0
     */
    public function __construct(
        private EventConsumerDefinition $definition,
        private IntegrationLedger $ledger,
    ) {
    }

    /**
     * Return the exact signed consumer contract implemented here.
     *
     * @return  EventConsumerDefinition  Aggregate-version idempotency and ordering declaration.
     *
     * @since   2.0.0
     */
    public function definition(): EventConsumerDefinition
    {
        return $this->definition;
    }

    /**
     * Validate worker scope and record only this component's inbox-processed inspection mutations.
     *
     * @param   IntegrationEvent  $event    Durable core record mutation event.
     * @param   ExecutionContext  $context  Fresh worker-owned site context.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the worker context and immutable event site disagree.
     *
     * @since   2.0.0
     */
    public function handle(IntegrationEvent $event, ExecutionContext $context): void
    {
        if ($context->site()->identifier() !== $event->siteIdentifier()) {
            throw new InvalidArgumentException('The inspection consumer requires the event-owning site context.');
        }
        if (InspectionMutation::belongsToInspection($event)) {
            $this->ledger->recordIntegration($event);
        }
    }
}
