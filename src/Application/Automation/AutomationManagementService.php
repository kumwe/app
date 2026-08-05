<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\Job\ScheduleRepository;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Identity\Domain\Capability;
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
        private AuthorizationGateway $authorization,
        private JobExecutionScope $jobScope,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function schedules(ExecutionContext $context): array
    {
        return $this->schedules->all($context);
    }

    /** @return array<string, mixed> */
    public function schedule(ExecutionContext $context, string $id): array
    {
        $this->assertId($id);

        return $this->schedules->find($context, $id)
            ?? throw new AutomationNotFound('The schedule does not exist.');
    }

    /** @return list<array<string, mixed>> */
    public function jobs(ExecutionContext $context, int $limit = 100): array
    {
        return $this->jobs->all($context, $limit);
    }

    /** @return list<string> */
    public function jobTypes(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('job'));
        return array_values(array_filter(
            $this->handlers->types(),
            fn (string $type): bool => !$this->jobScope->isInstallationGlobal($type)
                || $this->authorization->decide(
                    $context,
                    Capability::fromString('automation.manage'),
                    AuthorizationResource::item('automation_installation', $type),
                )->allowed,
        ));
    }

    /** @param array<string, mixed> $payload */
    public function createSchedule(
        ExecutionContext $context,
        string $name,
        string $cronExpression,
        string $timezone,
        string $jobType,
        array $payload,
        string $queue,
        DateTimeImmutable $firstRun,
    ): string {
        $this->authorize($context, AuthorizationResource::collection('schedule'));
        $actorId = $context->actorId();
        if ($this->handlers->find($jobType) === null) {
            throw new InvalidArgumentException('The schedule job type has no registered handler.');
        }

        return $this->transactions->transactional(function () use (
            $context,
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
                $context,
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
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        bool $enabled,
    ): void {
        $actorId = $context->actorId();
        $this->assertId($id);
        $this->transactions->transactional(function () use (
            $context,
            $actorId,
            $id,
            $expectedVersion,
            $enabled,
        ): void {
            $this->schedules->setEnabled($context, $id, $expectedVersion, $enabled);
            $this->record(
                $actorId,
                $enabled ? 'automation.schedule.enable' : 'automation.schedule.disable',
                'schedule',
                $id,
            );
        });
    }

    public function deleteSchedule(ExecutionContext $context, string $id, int $expectedVersion): void
    {
        $actorId = $context->actorId();
        $this->assertId($id);
        $this->transactions->transactional(function () use ($context, $actorId, $id, $expectedVersion): void {
            $this->schedules->delete($context, $id, $expectedVersion);
            $this->record($actorId, 'automation.schedule.delete', 'schedule', $id);
        });
    }

    public function retryJob(ExecutionContext $context, string $id): void
    {
        $actorId = $context->actorId();
        $this->assertId($id);
        $this->transactions->transactional(function () use ($context, $actorId, $id): void {
            $this->jobs->retry($context, $id);
            $this->record($actorId, 'automation.job.retry', 'job', $id);
        });
    }

    public function cancelJob(ExecutionContext $context, string $id): void
    {
        $actorId = $context->actorId();
        $this->assertId($id);
        $this->transactions->transactional(function () use ($context, $actorId, $id): void {
            $this->jobs->cancel($context, $id);
            $this->record($actorId, 'automation.job.cancel', 'job', $id);
        });
    }

    private function assertId(string $id): void
    {
        if (!Uuid::isValid($id) || strtolower($id) !== $id) {
            throw new InvalidArgumentException('Automation resource identifiers must be canonical UUIDs.');
        }
    }

    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            $resource,
        );
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
