<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\Application\Automation\JobHandlerRegistry;
use Kumwe\CMS\Application\Automation\JobLeaseContext;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\Application\Automation\LeaseAwareJobHandler;
use Kumwe\CMS\Application\Automation\StoredJob;
use Kumwe\CMS\Application\Automation\Worker;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Worker::class)]
#[CoversClass(JobLeaseContext::class)]
final class WorkerTest extends TestCase
{
    public function testLeaseAwareHandlerCanRenewAndCompleteFencedJob(): void
    {
        $queue = new RecordingJobQueue([$this->job('lease-aware')]);
        $handler = new RenewingHandler();
        $worker = new Worker($queue, new JobHandlerRegistry([$handler]), AuthorizationContext::gateway());

        self::assertTrue($worker->runOnce($this->context(), 'default', 'worker-one', 30));
        self::assertSame([45], $queue->renewals);
        self::assertSame(['00000000-0000-7000-8000-000000000001'], $queue->completed);
        self::assertSame(3, $queue->heartbeats);
    }

    public function testTransientFailureIsReleasedThroughQueuePolicy(): void
    {
        $queue = new RecordingJobQueue([$this->job('failing')]);
        $worker = new Worker(
            $queue,
            new JobHandlerRegistry([new FailingHandler()]),
            AuthorizationContext::gateway(),
        );

        self::assertTrue($worker->runOnce($this->context(), 'default', 'worker-one'));
        self::assertSame([false], $queue->permanentFailures);
        self::assertSame([], $queue->completed);
    }

    public function testUnknownTypeIsPermanentlyDeadLettered(): void
    {
        $queue = new RecordingJobQueue([$this->job('unknown')]);
        $worker = new Worker($queue, new JobHandlerRegistry([]), AuthorizationContext::gateway());

        self::assertTrue($worker->runOnce($this->context(), 'default', 'worker-one'));
        self::assertSame([true], $queue->permanentFailures);
    }

    public function testEmptyQueueOnlyPublishesIdleHeartbeat(): void
    {
        $queue = new RecordingJobQueue([]);
        $worker = new Worker($queue, new JobHandlerRegistry([]), AuthorizationContext::gateway());

        self::assertFalse($worker->runOnce($this->context(), 'default', 'worker-one'));
        self::assertSame(1, $queue->heartbeats);
    }

    private function job(string $type): StoredJob
    {
        return new StoredJob(
            '00000000-0000-7000-8000-000000000001',
            'default',
            $type,
            [],
            1,
            1,
            5,
            '00000000-0000-7000-8000-000000000101',
        );
    }

    private function context(): ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::Worker)->context(
            SiteContext::default(),
            'worker-test-request',
        );
    }
}

final class RecordingJobQueue implements JobQueue
{
    /** @var list<StoredJob> */
    private array $jobs;
    /** @var list<int> */
    public array $renewals = [];
    /** @var list<string> */
    public array $completed = [];
    /** @var list<bool> */
    public array $permanentFailures = [];
    public int $heartbeats = 0;

    /** @param list<StoredJob> $jobs */
    public function __construct(array $jobs)
    {
        $this->jobs = $jobs;
    }

    public function enqueue(
        ExecutionContext $context,
        string $type,
        array $payload,
        DateTimeImmutable $availableAt,
        string $queue = 'default',
        int $priority = 0,
        int $maximumAttempts = 5,
    ): string {
        return '00000000-0000-7000-8000-000000000001';
    }

    public function claim(
        ExecutionContext $context,
        string $queue,
        string $workerId,
        int $leaseSeconds,
    ): ?StoredJob
    {
        return array_shift($this->jobs);
    }

    public function renew(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        int $leaseSeconds,
    ): void
    {
        $this->renewals[] = $leaseSeconds;
    }

    public function complete(ExecutionContext $context, StoredJob $job, string $workerId): void
    {
        $this->completed[] = $job->id;
    }

    public function fail(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        Throwable $failure,
        bool $permanent,
    ): void
    {
        $this->permanentFailures[] = $permanent;
    }

    public function heartbeat(
        ExecutionContext $context,
        string $workerId,
        string $queue,
        ?string $jobId = null,
    ): void
    {
        $this->heartbeats++;
    }

    public function disconnect(ExecutionContext $context, string $workerId, string $queue): void
    {
    }

    public function all(ExecutionContext $context, int $limit = 100): array
    {
        return [];
    }

    public function retry(ExecutionContext $context, string $id): void
    {
    }

    public function cancel(ExecutionContext $context, string $id): void
    {
    }
}

final class RenewingHandler implements LeaseAwareJobHandler
{
    public function type(): string
    {
        return 'lease-aware';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        throw new \LogicException('The lease-aware entry point must be used.');
    }

    public function handleWithLease(
        array $payload,
        ExecutionContext $context,
        JobLeaseContext $lease,
    ): void
    {
        $lease->renew(45);
    }
}

final class FailingHandler implements JobHandler
{
    public function type(): string
    {
        return 'failing';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        throw new \RuntimeException('Expected failure.');
    }
}
