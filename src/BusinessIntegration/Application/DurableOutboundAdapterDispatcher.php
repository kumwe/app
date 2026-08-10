<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use Kumwe\CMS\Application\Automation\PermanentFailure;
use Kumwe\CMS\Application\Automation\RetryPolicy;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\CMS\BusinessIntegration\Domain\WebhookContributionDefinition;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Applies one outbound adapter behind the same durable inbox used by internal consumers.
 *
 * @since  2.0.0
 */
final readonly class DurableOutboundAdapterDispatcher
{
    /** @since 2.0.0 */
    public function __construct(
        private InboxStore $inbox,
        private EventContractRegistry $contracts,
        private RetryPolicy $retries,
        private TrustedRuntimeGenerationGuard $runtime,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Deliver once under a fenced receipt; repeat calls return without repeating a completed effect.
     *
     * @since 2.0.0
     */
    public function dispatch(
        WebhookContributionDefinition $definition,
        IntegrationEventTransport $adapter,
        IntegrationEvent $event,
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds = 60,
    ): InboxDisposition {
        $this->runtime->assertCurrent($runtimeGeneration);
        $this->contracts->assertEvent($event);
        if (!$definition->accepts($event->eventType(), $event->schemaVersion())) {
            $receipt = new EventConsumerDefinition(
                $definition->identifier(),
                $event->eventType(),
                $definition->schemaVersions(),
                $definition->handlerVersion(),
                $definition->queue(),
                false,
                $definition->idempotency(),
                $definition->maximumAttempts(),
                $definition->sensitivityCeiling(),
            );
            return $this->inbox->receive(
                $receipt,
                $event,
                $workerId,
                $runtimeGeneration,
                $leaseSeconds,
            )->disposition;
        }
        if (!$event->sensitivity()->allowedBy($definition->sensitivityCeiling())) {
            throw new PermanentFailure('The outbound adapter sensitivity ceiling rejects this event.');
        }
        if ($adapter->identifier() !== $definition->identifier()) {
            throw new PermanentFailure('The outbound adapter does not match its trusted declaration.');
        }

        $receipt = new EventConsumerDefinition(
            $definition->identifier(),
            $event->eventType(),
            $definition->schemaVersions(),
            $definition->handlerVersion(),
            $definition->queue(),
            false,
            $definition->idempotency(),
            $definition->maximumAttempts(),
            $definition->sensitivityCeiling(),
        );
        $result = $this->inbox->receive($receipt, $event, $workerId, $runtimeGeneration, $leaseSeconds);
        if ($result->lease === null) {
            if (in_array($result->disposition, [InboxDisposition::DUPLICATE], true)) {
                return $result->disposition;
            }
            if ($result->disposition === InboxDisposition::POISON) {
                throw new PermanentFailure('The outbound adapter delivery is quarantined as poison.');
            }
            throw new RuntimeException('The outbound adapter delivery is not currently claimable.');
        }

        try {
            $this->runtime->assertCurrent($runtimeGeneration);
            $adapter->publish($event);
            $this->inbox->complete($result->lease);
            $this->logger->info('Durable outbound adapter completed.', [
                'adapter_id' => $definition->identifier(),
                'event_id' => $event->eventId(),
                'attempt' => $result->lease->attempts,
                'runtime_generation' => $runtimeGeneration,
            ]);
        } catch (Throwable $failure) {
            $decision = $this->retries->decide(
                $failure,
                $result->lease->attempts,
                $definition->maximumAttempts(),
            );
            $this->inbox->fail(
                $result->lease,
                $decision->classification,
                $failure,
                $decision->retryAt,
            );
            throw $failure;
        }

        return InboxDisposition::CLAIMED;
    }
}
