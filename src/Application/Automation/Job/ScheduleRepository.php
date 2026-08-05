<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\ExecutionContext;

interface ScheduleRepository
{
    /** @param array<string, mixed> $payload */
    public function create(
        ExecutionContext $context,
        string $name,
        string $cronExpression,
        string $timezone,
        string $jobType,
        array $payload,
        string $queue,
        DateTimeImmutable $firstRun,
    ): string;

    /** @return list<array<string, mixed>> */
    public function all(ExecutionContext $context): array;

    /** @return array<string, mixed>|null */
    public function find(ExecutionContext $context, string $id): ?array;

    public function setEnabled(ExecutionContext $context, string $id, int $expectedVersion, bool $enabled): void;

    public function delete(ExecutionContext $context, string $id, int $expectedVersion): void;
}
