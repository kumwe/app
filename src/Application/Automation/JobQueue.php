<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Throwable;

interface JobQueue
{
    /** @param array<string, mixed> $payload */
    public function enqueue(
        ExecutionContext $context,
        string $type,
        array $payload,
        DateTimeImmutable $availableAt,
        string $queue = 'default',
        int $priority = 0,
        int $maximumAttempts = 5,
    ): string;

    public function claim(
        ExecutionContext $context,
        string $queue,
        string $workerId,
        int $leaseSeconds,
    ): ?StoredJob;

    /** Renew an active lease without changing its fencing token. */
    public function renew(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        int $leaseSeconds,
    ): void;

    public function complete(ExecutionContext $context, StoredJob $job, string $workerId): void;

    public function fail(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        Throwable $failure,
        bool $permanent,
    ): void;

    public function heartbeat(
        ExecutionContext $context,
        string $workerId,
        string $queue,
        ?string $jobId = null,
    ): void;

    public function disconnect(ExecutionContext $context, string $workerId, string $queue): void;

    /** @return list<array<string, mixed>> */
    public function all(ExecutionContext $context, int $limit = 100): array;

    public function retry(ExecutionContext $context, string $id): void;

    public function cancel(ExecutionContext $context, string $id): void;
}
