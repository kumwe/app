<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\Application\Automation\StoredJob;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final readonly class DoctrineJobQueue implements JobQueue
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private string $release,
    ) {
    }

    public function enqueue(
        string $type,
        array $payload,
        DateTimeImmutable $availableAt,
        string $queue = 'default',
        int $priority = 0,
        int $maximumAttempts = 5,
    ): string {
        $this->assertQueue($queue);
        $this->assertType($type);
        if ($priority < -100 || $priority > 100 || $maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('Job priority or maximum attempts are outside the supported range.');
        }

        $id = Uuid::uuid7()->toString();
        $now = $this->clock->now();
        $this->database->insert($this->tables->raw('jobs'), [
            'id' => $id,
            'queue' => $queue,
            'job_type' => $type,
            'schema_version' => 1,
            'payload' => $payload,
            'priority' => $priority,
            'status' => 'pending',
            'available_at' => $availableAt < $now ? $now : $availableAt,
            'lease_owner' => null,
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

        return $id;
    }

    public function claim(string $queue, string $workerId, int $leaseSeconds): ?StoredJob
    {
        $this->assertQueue($queue);
        $this->assertWorker($workerId);
        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('A job lease must last between 5 and 3600 seconds.');
        }

        return $this->transactions->transactional(function () use ($queue, $workerId, $leaseSeconds): ?StoredJob {
            $row = $this->database->fetchAssociative(sprintf(
                "SELECT * FROM %s WHERE queue = ? AND status = 'pending' AND available_at <= ? "
                . 'ORDER BY priority DESC, available_at, created_at, id LIMIT 1 FOR UPDATE SKIP LOCKED',
                $this->tables->quoted('jobs'),
            ), [$queue, $this->clock->now()], [Types::STRING, Types::DATETIME_IMMUTABLE]);

            if ($row === false || !is_string($row['id'] ?? null)) {
                return null;
            }

            $now = $this->clock->now();
            $affected = $this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'reserved', lease_owner = ?, lease_acquired_at = ?, "
                . 'lease_expires_at = ?, attempts = attempts + 1, updated_at = ? '
                . "WHERE id = ? AND status = 'pending'",
                $this->tables->quoted('jobs'),
            ), [
                $workerId,
                $now,
                $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
                $now,
                $row['id'],
            ], [
                Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE,
                Types::DATETIME_IMMUTABLE, Types::GUID,
            ]);
            $this->assertLeaseUpdated($affected);
            $row['attempts'] = $this->integer($row, 'attempts') + 1;

            return $this->map($row);
        });
    }

    public function complete(StoredJob $job, string $workerId): void
    {
        $this->assertWorker($workerId);
        $now = $this->clock->now();
        $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'completed', lease_owner = NULL, lease_acquired_at = NULL, "
            . 'lease_expires_at = NULL, completed_at = ?, updated_at = ? '
            . "WHERE id = ? AND status = 'reserved' AND lease_owner = ?",
            $this->tables->quoted('jobs'),
        ), [$now, $now, $job->id, $workerId], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
        ]));
    }

    public function fail(StoredJob $job, string $workerId, Throwable $failure, bool $permanent): void
    {
        $this->assertWorker($workerId);
        $dead = $permanent || $job->attempts >= $job->maximumAttempts;
        $this->transactions->transactional(function () use ($job, $workerId, $failure, $permanent, $dead): void {
            $now = $this->clock->now();
            if (!$dead) {
                $delay = min(3_600, 2 ** min($job->attempts, 11));
                $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
                    "UPDATE %s SET status = 'pending', lease_owner = NULL, lease_acquired_at = NULL, "
                    . 'lease_expires_at = NULL, available_at = ?, updated_at = ? '
                    . "WHERE id = ? AND status = 'reserved' AND lease_owner = ?",
                    $this->tables->quoted('jobs'),
                ), [$now->add(new DateInterval(sprintf('PT%dS', $delay))), $now, $job->id, $workerId], [
                    Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
                ]));
                return;
            }

            $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'dead', lease_owner = NULL, lease_acquired_at = NULL, "
                . "lease_expires_at = NULL, updated_at = ? WHERE id = ? AND status = 'reserved' AND lease_owner = ?",
                $this->tables->quoted('jobs'),
            ), [$now, $job->id, $workerId], [Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING]));
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

    public function heartbeat(string $workerId, string $queue, ?string $jobId = null): void
    {
        $this->assertWorker($workerId);
        $this->assertQueue($queue);
        $now = $this->clock->now();
        $exists = $this->database->fetchOne(sprintf(
            'SELECT worker_id FROM %s WHERE worker_id = ?',
            $this->tables->quoted('worker_heartbeats'),
        ), [$workerId]);
        $values = [
            'queue' => $queue,
            'process_id' => getmypid() ?: 1,
            'release' => $this->release,
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

    public function all(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The job list limit must be between 1 and 500.');
        }

        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT j.*, f.failure_classification, f.exception_type, f.error_message, f.failed_at '
            . 'FROM %s j LEFT JOIN %s f ON f.job_id = j.id '
            . 'ORDER BY j.created_at DESC, j.id DESC LIMIT %d',
            $this->tables->quoted('jobs'),
            $this->tables->quoted('failed_jobs'),
            $limit,
        ));

        return array_map($this->normalize(...), $rows);
    }

    public function retry(string $id): void
    {
        $this->transactions->transactional(function () use ($id): void {
            $now = $this->clock->now();
            $affected = $this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'pending', attempts = 0, available_at = ?, lease_owner = NULL, "
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

    public function cancel(string $id): void
    {
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
        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('A queued job payload must be a JSON object.');
        }

        return new StoredJob(
            $this->requiredString($row, 'id'),
            $this->requiredString($row, 'queue'),
            $this->requiredString($row, 'job_type'),
            $payload,
            $this->integer($row, 'schema_version'),
            $this->integer($row, 'attempts'),
            $this->integer($row, 'maximum_attempts'),
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        try {
            $payload = is_string($row['payload'] ?? null)
                ? json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR)
                : $row['payload'];
        } catch (JsonException $exception) {
            throw new RuntimeException('A queued job contains invalid JSON.', 0, $exception);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('A queued job payload must be a JSON object.');
        }

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

    private function assertLeaseUpdated(int $affected): void
    {
        if ($affected !== 1) {
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
}
