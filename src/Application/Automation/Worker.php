<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnership;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;
use RuntimeException;
use Throwable;

final readonly class Worker
{
    public function __construct(
        private JobQueue $queue,
        private JobHandlerRegistry $handlers,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnership $ownership,
        private SystemPrincipal $system,
        private JobExecutionScope $jobScope,
        private GlobalJobPrincipals $globalPrincipals,
    ) {
    }

    public function runOnce(
        ExecutionContext $context,
        string $queueName,
        string $workerId,
        int $leaseSeconds = 60,
        int $maximumHandlerSeconds = 240,
    ): bool {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('system.worker.operate'),
            AuthorizationResource::item('queue', $queueName),
        );
        $this->queue->heartbeat($context, $workerId, $queueName);
        $job = $this->queue->claim($context, $queueName, $workerId, $leaseSeconds);

        if ($job === null) {
            return false;
        }

        try {
            $executionClass = $this->jobScope->assertStoredClass($job->type, $job->executionClass);
            $jobContext = $executionClass === JobExecutionClass::Installation
                ? $this->globalPrincipals->context(
                    $job->type,
                    $this->jobScope,
                    'global-job-' . $job->id,
                    $context->correlationId(),
                )
                : $this->system->context(
                    $this->ownership->siteFor(AuthorizationResource::item('job', $job->id)),
                    'worker-job-' . $job->id,
                    $context->correlationId(),
                );
        } catch (AuthorizationResourceOwnershipUnknown) {
            // A site may be retired immediately after the claim transaction commits.
            // Never execute an orphaned job; its fenced lease will expire, while the
            // ownership-filtered claim query prevents it from being selected again.
            return true;
        } catch (Throwable $failure) {
            $this->queue->fail($context, $job, $workerId, $failure, true);

            return true;
        }
        try {
            $this->queue->heartbeat($context, $workerId, $queueName, $job->id);
            $handler = $this->handlers->find($job->type);

            if ($handler === null) {
                throw new PermanentFailure('No handler is registered for the job type.');
            }

            $this->handleWithinRuntimeLease(function () use (
                $context,
                $handler,
                $job,
                $jobContext,
                $leaseSeconds,
                $workerId,
                $queueName,
            ): void {
                if ($handler instanceof LeaseAwareJobHandler) {
                    $handler->handleWithLease(
                        $job->payload,
                        $jobContext,
                        new JobLeaseContext(
                            $job->id,
                            $leaseSeconds,
                            function (int $seconds) use ($context, $job, $workerId, $queueName): void {
                                $this->queue->renew($context, $job, $workerId, $seconds);
                                $this->queue->heartbeat($context, $workerId, $queueName, $job->id);
                            },
                        ),
                    );
                } else {
                    $handler->handle($job->payload, $jobContext);
                }
            }, $maximumHandlerSeconds);
            $this->queue->complete($context, $job, $workerId);
        } catch (Throwable $failure) {
            try {
                $this->queue->fail(
                    $context,
                    $job,
                    $workerId,
                    $failure,
                    $failure instanceof PermanentFailure,
                );
            } catch (AuthorizationDenied $denied) {
                if ($denied->resourceType !== 'job' || $denied->reason !== 'resource_site_unknown') {
                    throw $denied;
                }
                // Site retirement won the race with execution. Preserve the fence and
                // let the lease expire; disabled/orphaned jobs are excluded from claims.
            }
        }

        return true;
    }

    public function disconnect(ExecutionContext $context, string $workerId, string $queueName): void
    {
        $this->queue->disconnect($context, $workerId, $queueName);
    }

    /** @param callable(): void $operation */
    private function handleWithinRuntimeLease(callable $operation, int $maximumHandlerSeconds): void
    {
        if (
            $maximumHandlerSeconds < 1
            || !function_exists('pcntl_alarm')
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
        ) {
            throw new RuntimeException('Durable workers require a positive, enforceable handler runtime limit.');
        }
        pcntl_async_signals(true);
        $previous = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, static function (): never {
            throw new RuntimeException('The job exceeded its maximum runtime lease.');
        });
        pcntl_alarm($maximumHandlerSeconds);
        try {
            $operation();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous);
        }
    }
}
