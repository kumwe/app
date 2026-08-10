<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\RetryPolicy;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Claims and executes one process timer, command or compensation under a trusted generation.
 *
 * @since  2.0.0
 */
final readonly class ProcessWorkDispatcher
{
    /** @var list<ProcessWorkHandler> Exact active handler set. @since 2.0.0 */
    private array $handlers;

    /**
     * Assemble process work dispatch.
     *
     * @param   ProcessManagerStore            $store     Durable process repository.
     * @param   iterable<ProcessWorkHandler>   $handlers  Exact active handler set.
     * @param   RetryPolicy                    $retries   Failure classification and backoff.
     * @param   TrustedRuntimeGenerationGuard  $runtime   Trusted runtime authority guard.
     * @param   LoggerInterface                $logger    Structured observability sink.
     *
     * @since   2.0.0
     */
    public function __construct(
        private ProcessManagerStore $store,
        iterable $handlers,
        private RetryPolicy $retries,
        private TrustedRuntimeGenerationGuard $runtime,
        private LoggerInterface $logger,
    ) {
        $this->handlers = [...$handlers];
    }

    /**
     * Execute at most one due work item.
     *
     * @param   ExecutionContext  $context            Freshly authorised worker context.
     * @param   string            $workerId           Process identity.
     * @param   string            $runtimeGeneration  Exact loaded runtime generation.
     * @param   int               $leaseSeconds       Work lease duration.
     *
     * @return  bool  True when work was claimed, including work rescheduled after failure.
     *
     * @since   2.0.0
     */
    public function dispatchOne(
        ExecutionContext $context,
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds = 60,
    ): bool {
        $this->runtime->assertCurrent($runtimeGeneration);
        $lease = $this->store->claimWork($workerId, $runtimeGeneration, $leaseSeconds);
        if ($lease === null) {
            return false;
        }
        try {
            $this->runtime->assertCurrent($lease->runtimeGeneration);
            $matches = array_values(array_filter(
                $this->handlers,
                static fn (ProcessWorkHandler $handler): bool => $handler->supports(
                    $lease->work->kind(),
                    $lease->work->name(),
                ),
            ));
            if (count($matches) !== 1) {
                throw new LogicException('Process work must resolve to exactly one active handler.');
            }
            $matches[0]->handle($lease, $context);
            $this->store->completeWork($lease);
            $this->logger->info('Process work completed.', [
                'process_id' => $lease->processId,
                'work_id' => $lease->work->id(),
                'work_kind' => $lease->work->kind()->value,
                'attempt' => $lease->attempts,
                'runtime_generation' => $lease->runtimeGeneration,
            ]);
        } catch (Throwable $failure) {
            $decision = $this->retries->decide(
                $failure,
                $lease->attempts,
                $lease->work->maximumAttempts(),
            );
            $this->store->failWork($lease, $decision->classification, $failure, $decision->retryAt);
            $this->logger->warning('Process work failed.', [
                'process_id' => $lease->processId,
                'work_id' => $lease->work->id(),
                'work_kind' => $lease->work->kind()->value,
                'classification' => $decision->classification->value,
                'will_retry' => $decision->shouldRetry,
                'exception' => $failure,
            ]);
        }
        return true;
    }
}
