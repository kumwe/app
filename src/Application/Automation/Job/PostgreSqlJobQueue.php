<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use DateTimeImmutable;
use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use JsonException;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\Application\Automation\StoredJob;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final readonly class PostgreSqlJobQueue implements JobQueue
{
    public function __construct(
        private DatabaseInterface $database,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private string $schema,
        private string $release,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }
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
        $availableAt = $availableAt < $now ? $now : $availableAt;
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sql = sprintf(
            'INSERT INTO %s (id, queue, job_type, schema_version, payload, priority, status, available_at, '
            . 'attempts, maximum_attempts, created_at, updated_at) VALUES (%s, %s, %s, 1, %s::jsonb, %d, '
            . "'pending', %s, 0, %d, %s, %s)",
            $this->table('jobs'),
            $this->quote($id),
            $this->quote($queue),
            $this->quote($type),
            $this->quote($json),
            $priority,
            $this->quote($this->timestamp($availableAt)),
            $maximumAttempts,
            $this->quote($this->timestamp($now)),
            $this->quote($this->timestamp($now)),
        );
        $this->database->setQuery($sql)->execute();

        return $id;
    }

    public function claim(string $queue, string $workerId, int $leaseSeconds): ?StoredJob
    {
        $this->assertQueue($queue);
        $this->assertWorker($workerId);

        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('A job lease must last between 5 and 3600 seconds.');
        }

        $sql = sprintf(
            "WITH candidate AS (SELECT id FROM %1\$s WHERE queue = %2\$s AND status = 'pending' "
            . 'AND available_at <= CURRENT_TIMESTAMP ORDER BY priority DESC, available_at, created_at, id '
            . "FOR UPDATE SKIP LOCKED LIMIT 1) UPDATE %1\$s AS job SET status = 'reserved', "
            . 'lease_owner = %3$s, lease_acquired_at = CURRENT_TIMESTAMP, '
            . 'lease_expires_at = CURRENT_TIMESTAMP + make_interval(secs => %4$d), attempts = attempts + 1, '
            . 'updated_at = CURRENT_TIMESTAMP FROM candidate WHERE job.id = candidate.id RETURNING job.*',
            $this->table('jobs'),
            $this->quote($queue),
            $this->quote($workerId),
            $leaseSeconds,
        );
        $row = $this->database->setQuery($sql)->loadAssoc();

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $this->map($row);
    }

    public function complete(StoredJob $job, string $workerId): void
    {
        $this->assertWorker($workerId);
        $sql = sprintf(
            "UPDATE %s SET status = 'completed', lease_owner = NULL, lease_acquired_at = NULL, "
            . 'lease_expires_at = NULL, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP '
            . "WHERE id = %s AND status = 'reserved' AND lease_owner = %s",
            $this->table('jobs'),
            $this->quote($job->id),
            $this->quote($workerId),
        );
        $this->database->setQuery($sql)->execute();
        $this->assertLeaseUpdated();
    }

    public function fail(StoredJob $job, string $workerId, Throwable $failure, bool $permanent): void
    {
        $this->assertWorker($workerId);
        $dead = $permanent || $job->attempts >= $job->maximumAttempts;

        $this->transactions->transactional(function () use ($job, $workerId, $failure, $permanent, $dead): void {
            if (!$dead) {
                $delay = min(3_600, 2 ** min($job->attempts, 11));
                $sql = sprintf(
                    "UPDATE %s SET status = 'pending', lease_owner = NULL, lease_acquired_at = NULL, "
                    . 'lease_expires_at = NULL, available_at = CURRENT_TIMESTAMP + make_interval(secs => %d), '
                    . "updated_at = CURRENT_TIMESTAMP WHERE id = %s AND status = 'reserved' AND lease_owner = %s",
                    $this->table('jobs'),
                    $delay,
                    $this->quote($job->id),
                    $this->quote($workerId),
                );
                $this->database->setQuery($sql)->execute();
                $this->assertLeaseUpdated();

                return;
            }

            $sql = sprintf(
                "UPDATE %s SET status = 'dead', lease_owner = NULL, lease_acquired_at = NULL, "
                . 'lease_expires_at = NULL, updated_at = CURRENT_TIMESTAMP '
                . "WHERE id = %s AND status = 'reserved' AND lease_owner = %s",
                $this->table('jobs'),
                $this->quote($job->id),
                $this->quote($workerId),
            );
            $this->database->setQuery($sql)->execute();
            $this->assertLeaseUpdated();
            $payload = json_encode(
                $job->payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $failed = sprintf(
                'INSERT INTO %s (id, job_id, queue, job_type, schema_version, payload, attempts, maximum_attempts, '
                . 'failure_classification, exception_type, error_message, failed_at, created_at) VALUES '
                . '(%s, %s, %s, %s, %d, %s::jsonb, %d, %d, %s, %s, %s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                $this->table('failed_jobs'),
                $this->quote(Uuid::uuid7()->toString()),
                $this->quote($job->id),
                $this->quote($job->queue),
                $this->quote($job->type),
                $job->schemaVersion,
                $this->quote($payload),
                $job->attempts,
                $job->maximumAttempts,
                $this->quote($permanent ? 'permanent' : 'transient'),
                $this->quote($failure::class),
                $this->quote(substr($failure->getMessage(), 0, 4_000)),
            );
            $this->database->setQuery($failed)->execute();
        });
    }

    public function heartbeat(string $workerId, string $queue, ?string $jobId = null): void
    {
        $this->assertWorker($workerId);
        $this->assertQueue($queue);
        $processId = getmypid();

        if ($processId === false) {
            $processId = 1;
        }

        $sql = sprintf(
            'INSERT INTO %s (worker_id, queue, process_id, release, started_at, heartbeat_at, current_job_id) '
            . 'VALUES (%s, %s, %d, %s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, %s) '
            . 'ON CONFLICT (worker_id) DO UPDATE SET queue = EXCLUDED.queue, heartbeat_at = CURRENT_TIMESTAMP, '
            . 'current_job_id = EXCLUDED.current_job_id, release = EXCLUDED.release',
            $this->table('worker_heartbeats'),
            $this->quote($workerId),
            $this->quote($queue),
            $processId,
            $this->quote($this->release),
            $jobId === null ? 'NULL' : $this->quote($jobId),
        );
        $this->database->setQuery($sql)->execute();
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

        /** @var array<string, mixed> $payload */
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

    private function assertLeaseUpdated(): void
    {
        if ($this->database->getAffectedRows() !== 1) {
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

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }

    private function table(string $name): string
    {
        $quoted = $this->database->quoteName($this->schema . '.' . $name);

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted table.');
        }

        return $quoted;
    }

    private function quote(string $value): string
    {
        $quoted = $this->database->quote($value);

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted value.');
        }

        return $quoted;
    }
}
