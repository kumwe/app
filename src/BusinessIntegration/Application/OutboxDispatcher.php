<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use Kumwe\App\Application\Automation\RetryPolicy;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Claims, validates and publishes one outbox event under an exact trusted runtime generation.
 *
 * @since  2.0.0
 */
final readonly class OutboxDispatcher
{
    /**
     * Assemble the at-least-once dispatch boundary.
     *
     * @param  OutboxStore                    $outbox     Durable event queue.
     * @param  EventContractRegistry          $contracts  Exact event contract catalog.
     * @param  IntegrationEventFanout         $transport  Host-owned active runtime fan-out.
     * @param  RetryPolicy                    $retries    Failure classification and backoff.
     * @param  TrustedRuntimeGenerationGuard  $runtime    Runtime authority guard.
     * @param  LoggerInterface                $logger     Structured observability sink.
     *
     * @since  2.0.0
     */
    public function __construct(
        private OutboxStore $outbox,
        private EventContractRegistry $contracts,
        private IntegrationEventFanout $transport,
        private RetryPolicy $retries,
        private TrustedRuntimeGenerationGuard $runtime,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatch at most one event.
     *
     * @param   string  $workerId           Stable process identity.
     * @param   string  $runtimeGeneration  Exact trusted generation loaded by this process.
     * @param   int     $leaseSeconds       Dispatch lease duration.
     *
     * @return  bool  True when an event was claimed, including one that failed and was rescheduled.
     *
     * @since   2.0.0
     */
    public function dispatchOne(string $workerId, string $runtimeGeneration, int $leaseSeconds = 60): bool
    {
        $this->runtime->assertCurrent($runtimeGeneration);
        $lease = $this->outbox->claim($workerId, $runtimeGeneration, $leaseSeconds);
        if ($lease === null) {
            return false;
        }
        try {
            $this->runtime->assertCurrent($lease->runtimeGeneration);
            $this->contracts->assertEvent($lease->event);
            if (!$lease->event->sensitivity()->allowedBy($this->transport->sensitivityCeiling())) {
                throw new LogicException('The transport sensitivity ceiling rejects this event.');
            }
            $this->transport->publish($lease->event);
            $this->outbox->complete($lease);
            $this->logger->info('Integration event dispatched.', [
                'event_id' => $lease->event->eventId(),
                'correlation_id' => $lease->event->correlationId(),
                'causation_id' => $lease->event->causationId(),
                'event_type' => $lease->event->eventType(),
                'transport' => $this->transport->identifier(),
                'attempt' => $lease->attempts,
                'runtime_generation' => $lease->runtimeGeneration,
            ]);
        } catch (IntegrationDeliveryBackpressure $backpressure) {
            $this->outbox->defer($lease, $backpressure->delaySeconds);
            $this->logger->info('Integration event delivery deferred by queue backpressure.', [
                'event_id' => $lease->event->eventId(),
                'correlation_id' => $lease->event->correlationId(),
                'causation_id' => $lease->event->causationId(),
                'event_type' => $lease->event->eventType(),
                'transport' => $this->transport->identifier(),
                'delay_seconds' => $backpressure->delaySeconds,
                'runtime_generation' => $lease->runtimeGeneration,
            ]);
        } catch (Throwable $failure) {
            $decision = $this->retries->decide($failure, $lease->attempts, $lease->maximumAttempts);
            $this->outbox->fail($lease, $decision->classification, $failure, $decision->retryAt);
            $this->logger->warning('Integration event dispatch failed.', [
                'event_id' => $lease->event->eventId(),
                'correlation_id' => $lease->event->correlationId(),
                'causation_id' => $lease->event->causationId(),
                'event_type' => $lease->event->eventType(),
                'transport' => $this->transport->identifier(),
                'attempt' => $lease->attempts,
                'classification' => $decision->classification->value,
                'will_retry' => $decision->shouldRetry,
                'exception' => $failure,
            ]);
        }
        return true;
    }
}
