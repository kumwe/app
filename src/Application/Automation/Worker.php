<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Domain\Capability;
use RuntimeException;
use Throwable;

final readonly class Worker
{
    public function __construct(
        private JobQueue $queue,
        private JobHandlerRegistry $handlers,
        private AuthorizationGateway $authorization,
    ) {
    }

    public function runOnce(
        ExecutionContext $context,
        string $queueName,
        string $workerId,
        int $leaseSeconds = 60,
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

        $this->queue->heartbeat($context, $workerId, $queueName, $job->id);
        $handler = $this->handlers->find($job->type);

        if ($handler === null) {
            $this->queue->fail(
                $context,
                $job,
                $workerId,
                new RuntimeException('No handler is registered for the job type.'),
                true,
            );

            return true;
        }

        try {
            if ($handler instanceof LeaseAwareJobHandler) {
                $handler->handleWithLease(
                    $job->payload,
                    $context,
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
                $handler->handle($job->payload, $context);
            }
            $this->queue->complete($context, $job, $workerId);
        } catch (PermanentFailure $failure) {
            $this->queue->fail($context, $job, $workerId, $failure, true);
        } catch (Throwable $failure) {
            $this->queue->fail($context, $job, $workerId, $failure, false);
        }

        return true;
    }

    public function disconnect(ExecutionContext $context, string $workerId, string $queueName): void
    {
        $this->queue->disconnect($context, $workerId, $queueName);
    }
}
