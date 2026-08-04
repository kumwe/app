<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\Job\ScheduleRepository;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

final readonly class AutomationManagementService
{
    public function __construct(
        private ScheduleRepository $schedules,
        private JobQueue $jobs,
        private JobHandlerRegistry $handlers,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function schedules(): array
    {
        return $this->schedules->all();
    }

    /** @return array<string, mixed> */
    public function schedule(string $id): array
    {
        $this->assertId($id);

        return $this->schedules->find($id) ?? throw new AutomationNotFound('The schedule does not exist.');
    }

    /** @return list<array<string, mixed>> */
    public function jobs(int $limit = 100): array
    {
        return $this->jobs->all($limit);
    }

    /** @return list<string> */
    public function jobTypes(): array
    {
        return $this->handlers->types();
    }

    /** @param array<string, mixed> $payload */
    public function createSchedule(
        string $actorId,
        string $name,
        string $cronExpression,
        string $timezone,
        string $jobType,
        array $payload,
        string $queue,
        DateTimeImmutable $firstRun,
    ): string {
        if ($this->handlers->find($jobType) === null) {
            throw new InvalidArgumentException('The schedule job type has no registered handler.');
        }

        return $this->transactions->transactional(function () use (
            $actorId,
            $name,
            $cronExpression,
            $timezone,
            $jobType,
            $payload,
            $queue,
            $firstRun,
        ): string {
            $id = $this->schedules->create(
                $name,
                $cronExpression,
                $timezone,
                $jobType,
                $payload,
                $queue,
                $firstRun,
            );
            $this->record($actorId, 'automation.schedule.create', 'schedule', $id, ['job_type' => $jobType]);

            return $id;
        });
    }

    public function setScheduleEnabled(
        string $actorId,
        string $id,
        int $expectedVersion,
        bool $enabled,
    ): void {
        $this->assertId($id);
        $this->transactions->transactional(function () use ($actorId, $id, $expectedVersion, $enabled): void {
            $this->schedules->setEnabled($id, $expectedVersion, $enabled);
            $this->record(
                $actorId,
                $enabled ? 'automation.schedule.enable' : 'automation.schedule.disable',
                'schedule',
                $id,
            );
        });
    }

    public function deleteSchedule(string $actorId, string $id, int $expectedVersion): void
    {
        $this->assertId($id);
        $this->transactions->transactional(function () use ($actorId, $id, $expectedVersion): void {
            $this->schedules->delete($id, $expectedVersion);
            $this->record($actorId, 'automation.schedule.delete', 'schedule', $id);
        });
    }

    public function retryJob(string $actorId, string $id): void
    {
        $this->assertId($id);
        $this->transactions->transactional(function () use ($actorId, $id): void {
            $this->jobs->retry($id);
            $this->record($actorId, 'automation.job.retry', 'job', $id);
        });
    }

    public function cancelJob(string $actorId, string $id): void
    {
        $this->assertId($id);
        $this->transactions->transactional(function () use ($actorId, $id): void {
            $this->jobs->cancel($id);
            $this->record($actorId, 'automation.job.cancel', 'job', $id);
        });
    }

    private function assertId(string $id): void
    {
        if (!Uuid::isValid($id) || strtolower($id) !== $id) {
            throw new InvalidArgumentException('Automation resource identifiers must be canonical UUIDs.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function record(
        string $actorId,
        string $action,
        string $subjectType,
        string $subjectId,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $actorId,
            $action,
            $subjectType,
            $subjectId,
            'success',
            $metadata,
        ));
    }
}
