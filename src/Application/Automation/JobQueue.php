<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateTimeImmutable;
use Throwable;

interface JobQueue
{
    /** @param array<string, mixed> $payload */
    public function enqueue(
        string $type,
        array $payload,
        DateTimeImmutable $availableAt,
        string $queue = 'default',
        int $priority = 0,
        int $maximumAttempts = 5,
    ): string;

    public function claim(string $queue, string $workerId, int $leaseSeconds): ?StoredJob;

    /** Renew an active lease without changing its fencing token. */
    public function renew(StoredJob $job, string $workerId, int $leaseSeconds): void;

    public function complete(StoredJob $job, string $workerId): void;

    public function fail(StoredJob $job, string $workerId, Throwable $failure, bool $permanent): void;

    public function heartbeat(string $workerId, string $queue, ?string $jobId = null): void;

    public function disconnect(string $workerId): void;

    /** @return list<array<string, mixed>> */
    public function all(int $limit = 100): array;

    public function retry(string $id): void;

    public function cancel(string $id): void;
}
