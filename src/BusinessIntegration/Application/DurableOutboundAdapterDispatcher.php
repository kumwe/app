<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\PermanentFailure;
use Kumwe\CMS\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\CMS\Application\Automation\RetryPolicy;
use Kumwe\CMS\BusinessIntegration\Domain\ConsumerIdempotency;
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
    /**
     * Create the durable outbound adapter dispatcher.
     *
     * @param  InboxStore                     $inbox      Durable receipt ledger used for idempotent delivery.
     * @param  EventContractRegistry          $contracts  Event contract registry used to validate every delivery.
     * @param  RetryPolicy                    $retries    Retry policy used to classify delivery failures.
     * @param  TrustedRuntimeGenerationGuard  $runtime    Trusted active extension runtime.
     * @param  LoggerInterface                $logger     Structured logger used for delivery observability.
     * @param  ?QueueRuntimePolicyCatalog     $policies   Active contributed queue limits; null preserves core
     *         defaults for isolated instances.
     *
     * @since  2.0.0
     */
    public function __construct(
        private InboxStore $inbox,
        private EventContractRegistry $contracts,
        private RetryPolicy $retries,
        private TrustedRuntimeGenerationGuard $runtime,
        private LoggerInterface $logger,
        private ?QueueRuntimePolicyCatalog $policies = null,
    ) {
    }

    /**
     * Deliver once under a fenced receipt; repeat calls return without repeating a completed effect.
     *
     * @param   WebhookContributionDefinition  $definition         Signed contribution definition governing the
     *          operation.
     * @param   IntegrationEventTransport      $adapter            Declared outbound transport that performs the
     *          delivery.
     * @param   IntegrationEvent               $event              Versioned event being validated or processed.
     * @param   string                         $workerId           Stable identity of the claiming worker.
     * @param   string                         $runtimeGeneration  Trusted runtime generation that owns the lease.
     * @param   ?int                           $leaseSeconds       Explicit delivery lease, or null for the queue
     *          policy/default.
     *
     * @return  InboxDisposition  Durable receipt disposition after the delivery attempt.
     *
     * @since   2.0.0
     */
    public function dispatch(
        WebhookContributionDefinition $definition,
        IntegrationEventTransport $adapter,
        IntegrationEvent $event,
        string $workerId,
        string $runtimeGeneration,
        ?int $leaseSeconds = null,
    ): InboxDisposition {
        $this->runtime->assertCurrent($runtimeGeneration);
        $this->contracts->assertEvent($event);
        $leaseSeconds = $this->leaseSeconds($definition->queue(), $leaseSeconds);
        if (!in_array($event->eventType(), $definition->eventTypes(), true)) {
            throw new PermanentFailure('The outbound adapter does not declare this event type.');
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
            $definition->idempotency() === ConsumerIdempotency::AGGREGATE_VERSION,
            $definition->idempotency(),
            $definition->maximumAttempts(),
            $definition->sensitivityCeiling(),
        );
        $result = $this->inbox->receive($receipt, $event, $workerId, $runtimeGeneration, $leaseSeconds);
        if ($result->lease === null) {
            if (in_array($result->disposition, [
                InboxDisposition::DUPLICATE,
                InboxDisposition::BUSY,
                InboxDisposition::REORDERED,
                InboxDisposition::UNAVAILABLE,
            ], true)) {
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
                $result->lease->consumer->maximumAttempts(),
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

    /**
     * Resolve the signed queue lease without silently widening an explicit request.
     *
     * @param   string  $queue      Declared outbound-delivery queue.
     * @param   ?int    $requested  Caller override, or null for the policy/default.
     *
     * @return  int  Effective lease duration.
     *
     * @throws  InvalidArgumentException  When an explicit lease exceeds the queue declaration.
     *
     * @since   2.0.0
     */
    private function leaseSeconds(string $queue, ?int $requested): int
    {
        $policy = $this->policies?->policy($queue);
        if ($policy !== null && $requested !== null && $requested > $policy->leaseSeconds) {
            throw new InvalidArgumentException('A contributed queue lease cannot exceed its signed policy.');
        }

        return $requested ?? $policy?->leaseSeconds ?? 60;
    }
}
