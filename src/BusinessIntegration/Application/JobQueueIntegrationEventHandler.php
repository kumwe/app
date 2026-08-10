<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationContractValidator;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Psr\Clock\ClockInterface;

/**
 * Durable consumer that hands a validated event envelope to the existing job queue.
 *
 * The original event ID is retained in the job payload as the handler's idempotency key. Queue enqueue
 * remains at-least-once, so the target job handler must durably deduplicate that ID before side effects.
 *
 * @since  2.0.0
 */
final readonly class JobQueueIntegrationEventHandler implements IntegrationEventHandler
{
    /**
     * Configure the declared consumer and destination job type.
     *
     * @param   EventConsumerDefinition  $definition  Durable consumer contract.
     * @param   JobQueue                 $jobs        Existing durable queue.
     * @param   ClockInterface           $clock       Supplies immediate availability.
     * @param   string                   $jobType     Registered target job handler type.
     *
     * @since   2.0.0
     */
    public function __construct(
        private EventConsumerDefinition $definition,
        private JobQueue $jobs,
        private ClockInterface $clock,
        private string $jobType,
    ) {
        IntegrationContractValidator::identifier($jobType, 'Integration-event job type');
    }

    /** @return EventConsumerDefinition Consumer contract. @since 2.0.0 */
    public function definition(): EventConsumerDefinition
    {
        return $this->definition;
    }

    /**
     * Enqueue the complete validated envelope with its stable event ID.
     *
     * @param   IntegrationEvent  $event    Durable event being consumed.
     * @param   ExecutionContext  $context  Freshly authorised worker context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(IntegrationEvent $event, ExecutionContext $context): void
    {
        $this->jobs->enqueue(
            $context,
            $this->jobType,
            ['event_id' => $event->eventId(), 'event' => $event->toArray()],
            $this->clock->now(),
            $this->definition->queue(),
            maximumAttempts: $this->definition->maximumAttempts(),
        );
    }
}
