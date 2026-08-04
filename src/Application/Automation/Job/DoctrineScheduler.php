<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Automation\CronExpression;
use Kumwe\CMS\Application\Automation\ScheduleOccurrenceKey;
use Kumwe\CMS\Application\Automation\Scheduler;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
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
    ) {
    }

    public function dispatchDue(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('The scheduler dispatch limit must be between 1 and 1000.');
        }

        return $this->transactions->transactional(function () use ($limit): int {
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT * FROM %s WHERE enabled = ? AND next_run_at <= ? '
                . 'ORDER BY next_run_at, id LIMIT %d FOR UPDATE SKIP LOCKED',
                $this->tables->quoted('schedules'),
                $limit,
            ), [true, $this->clock->now()], [Types::BOOLEAN, Types::DATETIME_IMMUTABLE]);

            foreach ($rows as $row) {
                $this->dispatch($row);
            }

            return count($rows);
        });
    }

    public function create(
        string $name,
        string $cronExpression,
        string $timezone,
        string $jobType,
        array $payload,
        string $queue,
        DateTimeImmutable $firstRun,
    ): string {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('A schedule name must contain 1 to 160 characters.');
        }
        new CronExpression($cronExpression);
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('The schedule timezone is invalid.');
        }
        $this->assertJobType($jobType);
        $this->assertQueue($queue);
        $id = Uuid::uuid7()->toString();
        $now = $this->clock->now();
        $this->database->insert($this->tables->raw('schedules'), [
            'id' => $id,
            'name' => $name,
            'cron_expression' => $cronExpression,
            'timezone' => $timezone,
            'queue' => $queue,
            'job_type' => $jobType,
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

        return $id;
    }

    public function all(): array
    {
        return array_map($this->normalize(...), $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s ORDER BY name',
            $this->tables->quoted('schedules'),
        )));
    }

    public function find(string $id): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ?',
            $this->tables->quoted('schedules'),
        ), [$id]);

        return $row === false ? null : $this->normalize($row);
    }

    public function setEnabled(string $id, int $expectedVersion, bool $enabled): void
    {
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

    public function delete(string $id, int $expectedVersion): void
    {
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('The expected schedule version must be positive.');
        }

        $affected = $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE id = ? AND version = ?',
            $this->tables->quoted('schedules'),
        ), [$id, $expectedVersion], [Types::GUID, Types::INTEGER]);

        if ($affected !== 1) {
            throw new InvalidArgumentException('The schedule does not exist or its version changed.');
        }
    }

    /** @param array<string, mixed> $row */
    private function dispatch(array $row): void
    {
        $id = $this->requiredString($row, 'id');
        $scheduledFor = $this->dateTime($row['next_run_at'] ?? null);
        $now = $this->clock->now();

        try {
            $this->database->insert($this->tables->raw('jobs'), [
                'id' => Uuid::uuid7()->toString(),
                'queue' => $this->requiredString($row, 'queue'),
                'job_type' => $this->requiredString($row, 'job_type'),
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
}
