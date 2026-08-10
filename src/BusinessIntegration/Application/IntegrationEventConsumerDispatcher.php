<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\RetryPolicy;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies one integration event through a durable consumer inbox under a pinned runtime generation.
 *
 * @since  2.0.0
 */
final readonly class IntegrationEventConsumerDispatcher
{
    /**
     * Assemble durable consumer execution.
     *
     * @param   InboxStore                     $inbox      Durable receipt and checkpoint ledger.
     * @param   EventContractRegistry          $contracts  Exact event and consumer catalog.
     * @param   RetryPolicy                    $retries    Failure classification and backoff.
     * @param   TrustedRuntimeGenerationGuard  $runtime    Trusted-generation guard.
     * @param   LoggerInterface                $logger     Structured observability sink.
     *
     * @since   2.0.0
     */
    public function __construct(
        private InboxStore $inbox,
        private EventContractRegistry $contracts,
        private RetryPolicy $retries,
        private TrustedRuntimeGenerationGuard $runtime,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Offer an event to one handler, executing only when its durable receipt is claimed.
     *
     * @param   IntegrationEvent         $event              Event delivered at least once.
     * @param   IntegrationEventHandler  $handler            Exact trusted handler implementation.
     * @param   ExecutionContext         $context            Freshly authorised worker context.
     * @param   string                   $workerId           Process identity.
     * @param   string                   $runtimeGeneration  Exact generation that selected the handler.
     * @param   int                      $leaseSeconds       Delivery lease duration.
     *
     * @return  InboxDisposition  Explicit deduplication, ordering or execution outcome.
     *
     * @since   2.0.0
     */
    public function consume(
        IntegrationEvent $event,
        IntegrationEventHandler $handler,
        ExecutionContext $context,
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds = 60,
    ): InboxDisposition {
        $this->runtime->assertCurrent($runtimeGeneration);
        $this->contracts->assertEvent($event);
        $registered = $this->contracts->consumer($handler->definition()->identifier());
        if ($registered->toArray() !== $handler->definition()->toArray()) {
            throw new InvalidArgumentException('The executable consumer does not match its trusted declaration.');
        }
        $result = $this->inbox->receive(
            $registered,
            $event,
            $workerId,
            $runtimeGeneration,
            $leaseSeconds,
        );
        if ($result->lease === null) {
            return $result->disposition;
        }
        $lease = $result->lease;
        try {
            $this->runtime->assertCurrent($lease->runtimeGeneration);
            $handler->handle($event, $context);
            $this->inbox->complete($lease);
            $this->logger->info('Integration event consumer completed.', [
                'consumer_id' => $registered->identifier(),
                'event_id' => $event->eventId(),
                'attempt' => $lease->attempts,
                'runtime_generation' => $lease->runtimeGeneration,
            ]);
        } catch (Throwable $failure) {
            $decision = $this->retries->decide($failure, $lease->attempts, $registered->maximumAttempts());
            $this->inbox->fail($lease, $decision->classification, $failure, $decision->retryAt);
            $this->logger->warning('Integration event consumer failed.', [
                'consumer_id' => $registered->identifier(),
                'event_id' => $event->eventId(),
                'attempt' => $lease->attempts,
                'classification' => $decision->classification->value,
                'will_retry' => $decision->shouldRetry,
                'exception' => $failure,
            ]);
            throw $failure;
        }
        return InboxDisposition::CLAIMED;
    }
}
