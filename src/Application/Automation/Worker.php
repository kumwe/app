<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use RuntimeException;
use Throwable;

final readonly class Worker
{
    public function __construct(private JobQueue $queue, private JobHandlerRegistry $handlers)
    {
    }

    public function runOnce(string $queueName, string $workerId, int $leaseSeconds = 60): bool
    {
        $this->queue->heartbeat($workerId, $queueName);
        $job = $this->queue->claim($queueName, $workerId, $leaseSeconds);

        if ($job === null) {
            return false;
        }

        $this->queue->heartbeat($workerId, $queueName, $job->id);
        $handler = $this->handlers->find($job->type);

        if ($handler === null) {
            $this->queue->fail(
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
                    new JobLeaseContext(
                        $job->id,
                        $leaseSeconds,
                        function (int $seconds) use ($job, $workerId, $queueName): void {
                            $this->queue->renew($job, $workerId, $seconds);
                            $this->queue->heartbeat($workerId, $queueName, $job->id);
                        },
                    ),
                );
            } else {
                $handler->handle($job->payload);
            }
        } catch (PermanentFailure $failure) {
            $this->queue->fail($job, $workerId, $failure, true);

            return true;
        } catch (Throwable $failure) {
            $this->queue->fail($job, $workerId, $failure, false);

            return true;
        }

        $this->queue->complete($job, $workerId);

        return true;
    }

    public function disconnect(string $workerId): void
    {
        $this->queue->disconnect($workerId);
    }
}
