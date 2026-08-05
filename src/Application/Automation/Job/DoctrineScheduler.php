<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnership;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Application\Automation\CronExpression;
use Kumwe\CMS\Application\Automation\JobExecutionClass;
use Kumwe\CMS\Application\Automation\JobExecutionScope;
use Kumwe\CMS\Application\Automation\ScheduleOccurrenceKey;
use Kumwe\CMS\Application\Automation\Scheduler;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class DoctrineScheduler implements Scheduler, ScheduleRepository
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnership $ownership,
        private ResourceSiteOwnershipWriter $ownershipWriter,
        private SystemPrincipal $system,
        private JobExecutionScope $jobScope,
    ) {
    }

    public function dispatchDue(ExecutionContext $context, int $limit = 100): int
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('system.scheduler.dispatch'),
            AuthorizationResource::collection('schedule'),
        );
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('The scheduler dispatch limit must be between 1 and 1000.');
        }

        return $this->transactions->transactional(function () use ($context, $limit): int {
            $scheduleOwnershipId = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? 'CAST(s.id AS VARCHAR)'
                : 's.id';
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT s.* FROM %s s WHERE (s.execution_scope = ? OR (s.execution_scope = ? '
                . 'AND EXISTS (SELECT 1 FROM %s o INNER JOIN %s site ON site.identifier = o.site_identifier '
                . 'WHERE o.resource_type = ? AND o.resource_id = %s AND site.enabled = ?))) '
                . 'AND s.enabled = ? AND s.next_run_at <= ? '
                . 'ORDER BY s.next_run_at, s.id LIMIT %d FOR UPDATE SKIP LOCKED',
                $this->tables->quoted('schedules'),
                $this->tables->quoted('resource_site_ownership'),
                $this->tables->quoted('sites'),
                $scheduleOwnershipId,
                $limit,
            ), [
                JobExecutionClass::Installation->value,
                JobExecutionClass::Site->value,
                'schedule',
                true,
                true,
                $this->clock->now(),
            ], [
                Types::STRING,
                Types::STRING,
                Types::STRING,
                Types::BOOLEAN,
                Types::BOOLEAN,
                Types::DATETIME_IMMUTABLE,
            ]);

            $dispatched = 0;
            foreach ($rows as $row) {
                $scheduleId = $this->requiredString($row, 'id');
                $jobType = $this->requiredString($row, 'job_type');
                $executionClass = $this->jobScope->assertStoredClass(
                    $jobType,
                    $this->requiredString($row, 'execution_scope'),
                );
                $site = null;
                if ($executionClass === JobExecutionClass::Site) {
                    try {
                        $site = $this->ownership->siteFor(AuthorizationResource::item('schedule', $scheduleId));
                    } catch (AuthorizationResourceOwnershipUnknown) {
                        continue;
                    }
                    if (!$this->lockEnabledSite($site)) {
                        continue;
                    }
                    $scheduleContext = $this->system->context(
                        $site,
                        'scheduler-schedule-' . $scheduleId,
                        $context->correlationId(),
                    );
                    $this->authorization->assertAllowed(
                        $scheduleContext,
                        Capability::fromString('system.scheduler.dispatch'),
                        AuthorizationResource::item('schedule', $scheduleId),
                    );
                }
                $this->dispatch($row, $site, $executionClass);
                $dispatched++;
            }

            return $dispatched;
        });
    }

    public function create(
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
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('A schedule name must contain 1 to 160 characters.');
        }
        new CronExpression($cronExpression);
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('The schedule timezone is invalid.');
        }
        $this->assertJobType($jobType);
        $this->authorizeJobType($context, $jobType);
        $executionClass = $this->jobScope->executionClass($jobType);
        $this->assertQueue($queue);
        $id = Uuid::uuid7()->toString();
        $now = $this->clock->now();
        $this->transactions->transactional(function () use (
            $context,
            $id,
            $name,
            $cronExpression,
            $timezone,
            $queue,
            $jobType,
            $executionClass,
            $payload,
            $firstRun,
            $now,
        ): void {
            $this->database->insert($this->tables->raw('schedules'), [
                'id' => $id,
                'name' => $name,
                'cron_expression' => $cronExpression,
                'timezone' => $timezone,
                'queue' => $queue,
                'job_type' => $jobType,
                'execution_scope' => $executionClass->value,
                'job_schema_version' => 1,
                'payload' => $payload,
                'priority' => 0,
                'maximum_attempts' => 5,
                'enabled' => true,
                'next_run_at' => $firstRun < $now ? $now : $firstRun,
                'last_run_at' => null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'payload' => Types::JSON,
                'enabled' => Types::BOOLEAN,
                'next_run_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
            if ($executionClass === JobExecutionClass::Site) {
                $this->ownershipWriter->record(AuthorizationResource::item('schedule', $id), $context->site());
            }
        });

        return $id;
    }

    public function all(ExecutionContext $context): array
    {
        $rows = array_map($this->normalize(...), $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s ORDER BY name',
            $this->tables->quoted('schedules'),
        )));

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => is_string($row['id'] ?? null)
                && $this->canManageRow($context, $row),
        ));
    }

    public function find(ExecutionContext $context, string $id): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ?',
            $this->tables->quoted('schedules'),
        ), [$id]);

        if ($row === false) {
            return null;
        }
        $this->authorizeRow($context, $row);

        return $this->normalize($row);
    }

    public function setEnabled(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        bool $enabled,
    ): void {
        $row = $this->scheduleRow($id);
        $this->authorizeRow($context, $row);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('The expected schedule version must be positive.');
        }

        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET enabled = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
            $this->tables->quoted('schedules'),
        ), [$enabled, $this->clock->now(), $id, $expectedVersion], [
            Types::BOOLEAN,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::INTEGER,
        ]);

        if ($affected !== 1) {
            throw new InvalidArgumentException('The schedule does not exist or its version changed.');
        }
    }

    public function delete(ExecutionContext $context, string $id, int $expectedVersion): void
    {
        $row = $this->scheduleRow($id);
        $executionClass = $this->authorizeRow($context, $row);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('The expected schedule version must be positive.');
        }

        $this->transactions->transactional(function () use (
            $context,
            $executionClass,
            $id,
            $expectedVersion,
        ): void {
            $affected = $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE id = ? AND version = ?',
                $this->tables->quoted('schedules'),
            ), [$id, $expectedVersion], [Types::GUID, Types::INTEGER]);

            if ((string) $affected !== '1') {
                throw new InvalidArgumentException('The schedule does not exist or its version changed.');
            }
            if ($executionClass === JobExecutionClass::Site) {
                $this->ownershipWriter->remove(AuthorizationResource::item('schedule', $id), $context->site());
            }
        });
    }

    /** @param array<string, mixed> $row */
    private function dispatch(
        array $row,
        ?\Kumwe\CMS\Application\Authorization\SiteContext $site,
        JobExecutionClass $executionClass,
    ): void {
        $id = $this->requiredString($row, 'id');
        $scheduledFor = $this->dateTime($row['next_run_at'] ?? null);
        $now = $this->clock->now();
        $jobId = Uuid::uuid7()->toString();

        try {
            $this->database->insert($this->tables->raw('jobs'), [
                'id' => $jobId,
                'queue' => $this->requiredString($row, 'queue'),
                'job_type' => $this->requiredString($row, 'job_type'),
                'execution_scope' => $executionClass->value,
                'schema_version' => $this->integer($row, 'job_schema_version'),
                'payload' => $this->payload($row['payload'] ?? null),
                'priority' => $this->integer($row, 'priority'),
                'status' => 'pending',
                'available_at' => $now,
                'attempts' => 0,
                'maximum_attempts' => $this->integer($row, 'maximum_attempts'),
                'schedule_id' => $id,
                'scheduled_for' => $scheduledFor,
                'occurrence_key' => (string) ScheduleOccurrenceKey::for($id, $scheduledFor),
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'payload' => Types::JSON,
                'available_at' => Types::DATETIME_IMMUTABLE,
                'scheduled_for' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
            if ($executionClass === JobExecutionClass::Site) {
                if ($site === null) {
                    throw new RuntimeException('A site-owned scheduled job has no durable site.');
                }
                $this->ownershipWriter->record(AuthorizationResource::item('job', $jobId), $site);
            }
        } catch (UniqueConstraintViolationException) {
            // A concurrent scheduler already emitted this occurrence.
        }

        $next = (new CronExpression($this->requiredString($row, 'cron_expression')))->next(
            $scheduledFor,
            $this->requiredString($row, 'timezone'),
        );
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET last_run_at = ?, next_run_at = ?, version = version + 1, updated_at = ? WHERE id = ?',
            $this->tables->quoted('schedules'),
        ), [$scheduledFor, $next, $now, $id], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(mixed $stored): array
    {
        try {
            $payload = is_string($stored) ? json_decode($stored, true, 64, JSON_THROW_ON_ERROR) : $stored;
        } catch (JsonException $exception) {
            throw new RuntimeException('A schedule payload contains invalid JSON.', 0, $exception);
        }
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new RuntimeException('A schedule payload must be a JSON object.');
        }
        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Schedule field %s is invalid.', $field));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Schedule field %s is not an integer.', $field));
        }
        return (int) $value;
    }

    private function dateTime(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value)) {
            throw new RuntimeException('A schedule timestamp is invalid.');
        }
        return new DateTimeImmutable($value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $row['payload'] = $this->payload($row['payload'] ?? null);
        $row['enabled'] = filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $row['version'] = $this->integer($row, 'version');

        return $row;
    }

    private function assertQueue(string $queue): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $queue) !== 1) {
            throw new InvalidArgumentException('The schedule queue is invalid.');
        }
    }

    private function assertJobType(string $type): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{2,127}$/D', $type) !== 1) {
            throw new InvalidArgumentException('The scheduled job type is invalid.');
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

    private function authorizeJobType(ExecutionContext $context, string $jobType): void
    {
        if (!$this->jobScope->isInstallationGlobal($jobType)) {
            return;
        }

        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', $jobType),
        );
    }

    /** @param array<string, mixed> $row */
    private function canManageRow(ExecutionContext $context, array $row): bool
    {
        $jobType = $this->requiredString($row, 'job_type');
        $executionClass = $this->jobScope->assertStoredClass(
            $jobType,
            $this->requiredString($row, 'execution_scope'),
        );
        $resource = $executionClass === JobExecutionClass::Installation
            ? AuthorizationResource::item('automation_installation', $jobType)
            : AuthorizationResource::item('schedule', $this->requiredString($row, 'id'));

        return $this->authorization->decide(
            $context,
            Capability::fromString('automation.manage'),
            $resource,
        )->allowed;
    }

    /** @param array<string, mixed> $row */
    private function authorizeRow(ExecutionContext $context, array $row): JobExecutionClass
    {
        $jobType = $this->requiredString($row, 'job_type');
        $executionClass = $this->jobScope->assertStoredClass(
            $jobType,
            $this->requiredString($row, 'execution_scope'),
        );
        $this->authorize(
            $context,
            $executionClass === JobExecutionClass::Installation
                ? AuthorizationResource::item('automation_installation', $jobType)
                : AuthorizationResource::item('schedule', $this->requiredString($row, 'id')),
        );

        return $executionClass;
    }

    /** @return array<string, mixed> */
    private function scheduleRow(string $id): array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT id, job_type, execution_scope FROM %s WHERE id = ?',
            $this->tables->quoted('schedules'),
        ), [$id]);
        if ($row === false) {
            throw new InvalidArgumentException('The schedule does not exist.');
        }

        return $row;
    }

    private function lockEnabledSite(\Kumwe\CMS\Application\Authorization\SiteContext $site): bool
    {
        return $this->database->fetchOne(sprintf(
            'SELECT identifier FROM %s WHERE identifier = ? AND enabled = ? FOR UPDATE',
            $this->tables->quoted('sites'),
        ), [$site->identifier(), true], [Types::STRING, Types::BOOLEAN]) !== false;
    }
}
