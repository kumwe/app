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
            $handler->handle($job->payload);
            $this->queue->complete($job, $workerId);
        } catch (PermanentFailure $failure) {
            $this->queue->fail($job, $workerId, $failure, true);
        } catch (Throwable $failure) {
            $this->queue->fail($job, $workerId, $failure, false);
        }

        return true;
    }
}
