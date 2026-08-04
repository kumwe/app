<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use DateTimeImmutable;

interface ScheduleRepository
{
    /** @param array<string, mixed> $payload */
    public function create(
        string $name,
        string $cronExpression,
        string $timezone,
        string $jobType,
        array $payload,
        string $queue,
        DateTimeImmutable $firstRun,
    ): string;

    /** @return list<array<string, mixed>> */
    public function all(): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    public function setEnabled(string $id, int $expectedVersion, bool $enabled): void;

    public function delete(string $id, int $expectedVersion): void;
}
