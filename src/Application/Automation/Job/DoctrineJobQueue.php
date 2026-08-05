<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Automation\JobExecutionClass;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\Application\Automation\JobExecutionScope;
use Kumwe\CMS\Application\Automation\StoredJob;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final readonly class DoctrineJobQueue implements JobQueue
{
    public const int EXHAUSTED_REAP_LIMIT = 100;

    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private string $release,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private JobExecutionScope $jobScope,
    ) {
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
        $this->authorize($context, AuthorizationResource::item('queue', $queue));
        $this->assertQueue($queue);
        $this->assertType($type);
        $this->authorizeJobType($context, $type);
        $executionClass = $this->jobScope->executionClass($type);
        if ($priority < -100 || $priority > 100 || $maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('Job priority or maximum attempts are outside the supported range.');
        }

        $id = Uuid::uuid7()->toString();
        $now = $this->clock->now();
        $this->transactions->transactional(function () use (
            $context,
            $id,
            $queue,
            $type,
            $payload,
            $executionClass,
            $priority,
            $maximumAttempts,
            $availableAt,
            $now,
        ): void {
            $this->database->insert($this->tables->raw('jobs'), [
                'id' => $id,
                'queue' => $queue,
                'job_type' => $type,
                'execution_scope' => $executionClass->value,
                'schema_version' => 1,
                'payload' => $payload,
                'priority' => $priority,
                'status' => 'pending',
                'available_at' => $availableAt < $now ? $now : $availableAt,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_acquired_at' => null,
                'lease_expires_at' => null,
                'attempts' => 0,
                'maximum_attempts' => $maximumAttempts,
                'schedule_id' => null,
                'scheduled_for' => null,
                'occurrence_key' => null,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'payload' => Types::JSON,
                'available_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
            if ($executionClass === JobExecutionClass::Site) {
                $this->ownership->record(AuthorizationResource::item('job', $id), $context->site());
            }
        });

        return $id;
    }

    public function claim(
        ExecutionContext $context,
        string $queue,
        string $workerId,
        int $leaseSeconds,
    ): ?StoredJob {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $queue));
        $this->assertQueue($queue);
        $this->assertWorker($workerId);
        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('A job lease must last between 5 and 3600 seconds.');
        }

        return $this->transactions->transactional(function () use ($queue, $workerId, $leaseSeconds): ?StoredJob {
            $now = $this->clock->now();
            $reaped = 0;

            while ($reaped < self::EXHAUSTED_REAP_LIMIT) {
                $row = $this->database->fetchAssociative(sprintf(
                    'SELECT j.* FROM %s j WHERE j.queue = ? AND (j.execution_scope = ? OR '
                    . '(j.execution_scope = ? AND EXISTS (SELECT 1 FROM %s o INNER JOIN %s s '
                    . 'ON s.identifier = o.site_identifier WHERE o.resource_type = ? '
                    . 'AND o.resource_id = j.id AND s.enabled = ?))) AND ('
                    . "(j.status = 'pending' AND j.available_at <= ?) OR "
                    . "(j.status = 'reserved' AND (j.lease_expires_at IS NULL OR j.lease_expires_at <= ?))"
                    . ') ORDER BY j.priority DESC, j.available_at, j.created_at, j.id '
                    . 'LIMIT 1 FOR UPDATE SKIP LOCKED',
                    $this->tables->quoted('jobs'),
                    $this->tables->quoted('resource_site_ownership'),
                    $this->tables->quoted('sites'),
                ), [
                    $queue,
                    JobExecutionClass::Installation->value,
                    JobExecutionClass::Site->value,
                    'job',
                    true,
                    $now,
                    $now,
                ], [
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::BOOLEAN,
                    Types::DATETIME_IMMUTABLE,
                    Types::DATETIME_IMMUTABLE,
                ]);

                if ($row === false || !is_string($row['id'] ?? null)) {
                    return null;
                }

                $executionClass = $this->jobScope->assertStoredClass(
                    $this->requiredString($row, 'job_type'),
                    $this->requiredString($row, 'execution_scope'),
                );
                if ($executionClass === JobExecutionClass::Site
                    && !$this->lockEnabledOwner($this->requiredString($row, 'id'))) {
                    return null;
                }

                $attempts = $this->integer($row, 'attempts');
                if ($attempts >= $this->integer($row, 'maximum_attempts')) {
                    $this->deadLetterExpired($row, $now);
                    $reaped++;
                    continue;
                }

                $token = Uuid::uuid7()->toString();
                $affected = $this->database->executeStatement(sprintf(
                    "UPDATE %s SET status = 'reserved', lease_owner = ?, lease_token = ?, lease_acquired_at = ?, "
                    . 'lease_expires_at = ?, attempts = attempts + 1, updated_at = ? WHERE id = ? AND ('
                    . "(status = 'pending' AND available_at <= ?) OR "
                    . "(status = 'reserved' AND (lease_expires_at IS NULL OR lease_expires_at <= ?)))",
                    $this->tables->quoted('jobs'),
                ), [
                    $workerId,
                    $token,
                    $now,
                    $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
                    $now,
                    $row['id'],
                    $now,
                    $now,
                ], [
                    Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE,
                    Types::DATETIME_IMMUTABLE, Types::GUID, Types::DATETIME_IMMUTABLE,
                    Types::DATETIME_IMMUTABLE,
                ]);
                $this->assertLeaseUpdated($affected);
                $row['attempts'] = $attempts + 1;
                $row['lease_token'] = $token;

                return $this->map($row);
            }

            return null;
        });
    }

    public function renew(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        int $leaseSeconds,
    ): void {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $job->queue));
        $this->assertWorker($workerId);
        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('A job lease must last between 5 and 3600 seconds.');
        }

        $now = $this->clock->now();
        $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
            'UPDATE %s SET lease_expires_at = ?, updated_at = ? WHERE id = ? '
            . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? AND lease_expires_at > ?",
            $this->tables->quoted('jobs'),
        ), [
            $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
            $now,
            $job->id,
            $workerId,
            $job->leaseToken,
            $now,
        ], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID,
            Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    public function complete(ExecutionContext $context, StoredJob $job, string $workerId): void
    {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $job->queue));
        $this->assertWorker($workerId);
        $now = $this->clock->now();
        $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'completed', lease_owner = NULL, lease_token = NULL, lease_acquired_at = NULL, "
            . 'lease_expires_at = NULL, completed_at = ?, updated_at = ? '
            . "WHERE id = ? AND status = 'reserved' AND lease_owner = ? AND lease_token = ? AND lease_expires_at > ?",
            $this->tables->quoted('jobs'),
        ), [$now, $now, $job->id, $workerId, $job->leaseToken, $now], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
            Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    public function fail(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        Throwable $failure,
        bool $permanent,
    ): void {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $job->queue));
        $this->assertWorker($workerId);
        $dead = $permanent || $job->attempts >= $job->maximumAttempts;
        $this->transactions->transactional(function () use ($job, $workerId, $failure, $permanent, $dead): void {
            $now = $this->clock->now();
            if (!$dead) {
                $delay = min(3_600, 2 ** min($job->attempts, 11));
                $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
                    "UPDATE %s SET status = 'pending', lease_owner = NULL, lease_token = NULL, "
                    . 'lease_acquired_at = NULL, '
                    . 'lease_expires_at = NULL, available_at = ?, updated_at = ? '
                    . "WHERE id = ? AND status = 'reserved' AND lease_owner = ? "
                    . 'AND lease_token = ? AND lease_expires_at > ?',
                    $this->tables->quoted('jobs'),
                ), [
                    $now->add(new DateInterval(sprintf('PT%dS', $delay))), $now, $job->id,
                    $workerId, $job->leaseToken, $now,
                ], [
                    Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
                    Types::STRING, Types::DATETIME_IMMUTABLE,
                ]));
                return;
            }

            $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'dead', lease_owner = NULL, lease_token = NULL, lease_acquired_at = NULL, "
                . "lease_expires_at = NULL, updated_at = ? WHERE id = ? AND status = 'reserved' AND lease_owner = ? "
                . 'AND lease_token = ? AND lease_expires_at > ?',
                $this->tables->quoted('jobs'),
            ), [$now, $job->id, $workerId, $job->leaseToken, $now], [
                Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING, Types::STRING,
                Types::DATETIME_IMMUTABLE,
            ]));
            $this->database->insert($this->tables->raw('failed_jobs'), [
                'id' => Uuid::uuid7()->toString(),
                'job_id' => $job->id,
                'queue' => $job->queue,
                'job_type' => $job->type,
                'schema_version' => $job->schemaVersion,
                'payload' => $job->payload,
                'attempts' => $job->attempts,
                'maximum_attempts' => $job->maximumAttempts,
                'failure_classification' => $permanent ? 'permanent' : 'transient',
                'exception_type' => $failure::class,
                'error_message' => substr($failure->getMessage(), 0, 4_000),
                'failed_at' => $now,
                'created_at' => $now,
            ], [
                'payload' => Types::JSON,
                'failed_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);
        });
    }

    public function heartbeat(
        ExecutionContext $context,
        string $workerId,
        string $queue,
        ?string $jobId = null,
    ): void {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $queue));
        $this->assertWorker($workerId);
        $this->assertQueue($queue);
        $now = $this->clock->now();
        $exists = $this->database->fetchOne(sprintf(
            'SELECT worker_id FROM %s WHERE worker_id = ?',
            $this->tables->quoted('worker_heartbeats'),
        ), [$workerId]);
        $processId = getmypid();
        $values = [
            'queue' => $queue,
            'process_id' => $processId === false ? 1 : $processId,
            'application_release' => $this->release,
            'heartbeat_at' => $now,
            'current_job_id' => $jobId,
        ];
        if ($exists === false) {
            $this->database->insert($this->tables->raw('worker_heartbeats'), [
                'worker_id' => $workerId,
                'started_at' => $now,
            ] + $values, ['started_at' => Types::DATETIME_IMMUTABLE, 'heartbeat_at' => Types::DATETIME_IMMUTABLE]);
            return;
        }
        $this->database->update(
            $this->tables->raw('worker_heartbeats'),
            $values,
            ['worker_id' => $workerId],
            ['heartbeat_at' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function disconnect(ExecutionContext $context, string $workerId, string $queue): void
    {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $queue));
        $this->assertWorker($workerId);
        $this->assertQueue($queue);
        $this->database->delete(
            $this->tables->raw('worker_heartbeats'),
            ['worker_id' => $workerId],
            ['worker_id' => Types::STRING],
        );
    }

    public function all(ExecutionContext $context, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The job list limit must be between 1 and 500.');
        }

        $result = [];
        $offset = 0;
        $pageSize = min(500, max(50, $limit));
        do {
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT j.*, f.failure_classification, f.exception_type, f.error_message, f.failed_at '
                . 'FROM %s j LEFT JOIN %s f ON f.job_id = j.id '
                . 'ORDER BY j.created_at DESC, j.id DESC LIMIT %d OFFSET %d',
                $this->tables->quoted('jobs'),
                $this->tables->quoted('failed_jobs'),
                $pageSize,
                $offset,
            ));
            foreach (array_map($this->normalize(...), $rows) as $row) {
                if (is_string($row['id'] ?? null) && $this->canManageRow($context, $row)) {
                    $result[] = $row;
                    if (count($result) === $limit) {
                        return $result;
                    }
                }
            }
            $offset += count($rows);
        } while (count($rows) === $pageSize);

        return $result;
    }

    public function retry(ExecutionContext $context, string $id): void
    {
        $this->authorizeJob($context, $id);
        $this->transactions->transactional(function () use ($id): void {
            $now = $this->clock->now();
            $affected = $this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'pending', attempts = 0, available_at = ?, lease_owner = NULL, "
                . 'lease_token = NULL, '
                . 'lease_acquired_at = NULL, lease_expires_at = NULL, completed_at = NULL, updated_at = ? '
                . "WHERE id = ? AND status = 'dead'",
                $this->tables->quoted('jobs'),
            ), [$now, $now, $id], [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID]);

            if ($affected !== 1) {
                throw new InvalidArgumentException('Only an existing dead job can be retried.');
            }

            $this->database->delete($this->tables->raw('failed_jobs'), ['job_id' => $id], ['job_id' => Types::GUID]);
        });
    }

    public function cancel(ExecutionContext $context, string $id): void
    {
        $this->authorizeJob($context, $id);
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'canceled', updated_at = ? WHERE id = ? AND status = 'pending'",
            $this->tables->quoted('jobs'),
        ), [$this->clock->now(), $id], [Types::DATETIME_IMMUTABLE, Types::GUID]);

        if ($affected !== 1) {
            throw new InvalidArgumentException('Only an existing pending job can be canceled.');
        }
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): StoredJob
    {
        try {
            $payload = is_string($row['payload'] ?? null)
                ? json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR)
                : $row['payload'];
        } catch (JsonException $exception) {
            throw new RuntimeException('A queued job contains invalid JSON.', 0, $exception);
        }
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new RuntimeException('A queued job payload must be a JSON object.');
        }
        /** @var array<string, mixed> $payload */

        return new StoredJob(
            $this->requiredString($row, 'id'),
            $this->requiredString($row, 'queue'),
            $this->requiredString($row, 'job_type'),
            $payload,
            $this->integer($row, 'schema_version'),
            $this->integer($row, 'attempts'),
            $this->integer($row, 'maximum_attempts'),
            $this->requiredString($row, 'lease_token'),
            $this->requiredString($row, 'execution_scope'),
        );
    }

    /** @param array<string, mixed> $row */
    private function deadLetterExpired(array $row, DateTimeImmutable $now): void
    {
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'dead', lease_owner = NULL, lease_token = NULL, "
            . 'lease_acquired_at = NULL, lease_expires_at = NULL, updated_at = ? WHERE id = ? AND '
            . "((status = 'pending' AND attempts >= maximum_attempts) OR (status = 'reserved' "
            . 'AND attempts >= maximum_attempts AND (lease_expires_at IS NULL OR lease_expires_at <= ?)))',
            $this->tables->quoted('jobs'),
        ), [$now, $this->requiredString($row, 'id'), $now], [
            Types::DATETIME_IMMUTABLE, Types::GUID, Types::DATETIME_IMMUTABLE,
        ]);
        $this->assertLeaseUpdated($affected);
        $this->database->insert($this->tables->raw('failed_jobs'), [
            'id' => Uuid::uuid7()->toString(),
            'job_id' => $this->requiredString($row, 'id'),
            'queue' => $this->requiredString($row, 'queue'),
            'job_type' => $this->requiredString($row, 'job_type'),
            'schema_version' => $this->integer($row, 'schema_version'),
            'payload' => is_string($row['payload'] ?? null)
                ? json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR)
                : ($row['payload'] ?? []),
            'attempts' => $this->integer($row, 'attempts'),
            'maximum_attempts' => $this->integer($row, 'maximum_attempts'),
            'failure_classification' => 'transient',
            'exception_type' => 'Kumwe\\CMS\\Application\\Automation\\ExpiredJobLease',
            'error_message' => 'The final worker lease expired before the job completed.',
            'failed_at' => $now,
            'created_at' => $now,
        ], [
            'payload' => Types::JSON,
            'failed_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        try {
            $payload = is_string($row['payload'] ?? null)
                ? json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR)
                : $row['payload'];
        } catch (JsonException $exception) {
            throw new RuntimeException('A queued job contains invalid JSON.', 0, $exception);
        }

        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new RuntimeException('A queued job payload must be a JSON object.');
        }
        /** @var array<string, mixed> $payload */

        $row['payload'] = $payload;
        $row['attempts'] = $this->integer($row, 'attempts');
        $row['maximum_attempts'] = $this->integer($row, 'maximum_attempts');

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Queued job field %s is invalid.', $field));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Queued job field %s is not an integer.', $field));
        }
        return (int) $value;
    }

    private function assertLeaseUpdated(int|string $affected): void
    {
        if ((string) $affected !== '1') {
            throw new RuntimeException('The worker no longer owns the active job lease.');
        }
    }

    private function assertQueue(string $queue): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $queue) !== 1) {
            throw new InvalidArgumentException('The queue name is invalid.');
        }
    }

    private function assertType(string $type): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{2,127}$/D', $type) !== 1) {
            throw new InvalidArgumentException('The job type is invalid.');
        }
    }

    private function assertWorker(string $worker): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $worker) !== 1) {
            throw new InvalidArgumentException('The worker identifier is invalid.');
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

    private function authorizeWorker(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('system.worker.operate'),
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
            : AuthorizationResource::item('job', $this->requiredString($row, 'id'));

        return $this->authorization->decide(
            $context,
            Capability::fromString('automation.manage'),
            $resource,
        )->allowed;
    }

    private function authorizeJob(ExecutionContext $context, string $id): void
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT id, job_type, execution_scope FROM %s WHERE id = ?',
            $this->tables->quoted('jobs'),
        ), [$id]);
        if ($row === false) {
            throw new InvalidArgumentException('The job does not exist.');
        }

        $jobType = $this->requiredString($row, 'job_type');
        $executionClass = $this->jobScope->assertStoredClass(
            $jobType,
            $this->requiredString($row, 'execution_scope'),
        );
        $this->authorize(
            $context,
            $executionClass === JobExecutionClass::Installation
                ? AuthorizationResource::item('automation_installation', $jobType)
                : AuthorizationResource::item('job', $id),
        );
    }

    private function lockEnabledOwner(string $jobId): bool
    {
        return $this->database->fetchOne(sprintf(
            'SELECT s.identifier FROM %s o INNER JOIN %s s ON s.identifier = o.site_identifier '
            . 'WHERE o.resource_type = ? AND o.resource_id = ? AND s.enabled = ? FOR UPDATE',
            $this->tables->quoted('resource_site_ownership'),
            $this->tables->quoted('sites'),
        ), ['job', $jobId, true], [Types::STRING, Types::STRING, Types::BOOLEAN]) !== false;
    }
}
