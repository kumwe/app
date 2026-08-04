<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use JsonException;
use Kumwe\CMS\Application\Automation\CronExpression;
use Kumwe\CMS\Application\Automation\ScheduleOccurrenceKey;
use Kumwe\CMS\Application\Automation\Scheduler;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class PostgreSqlScheduler implements Scheduler, ScheduleRepository
{
    public function __construct(
        private DatabaseInterface $database,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private string $schema,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }
    }

    public function dispatchDue(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('The scheduler dispatch limit must be between 1 and 1000.');
        }

        return $this->transactions->transactional(function () use ($limit): int {
            $rows = $this->database->setQuery(sprintf(
                "SELECT * FROM %s WHERE enabled = true AND next_run_at <= CURRENT_TIMESTAMP "
                . 'ORDER BY next_run_at, id FOR UPDATE SKIP LOCKED LIMIT %d',
                $this->table('schedules'),
                $limit,
            ))->loadAssocList();

            if (!is_array($rows)) {
                throw new RuntimeException('The due schedule query returned an invalid result set.');
            }

            $dispatched = 0;

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('The due schedule query returned an invalid row.');
                }

                $this->dispatch($row);
                ++$dispatched;
            }

            return $dispatched;
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
        new DateTimeZone($timezone);
        $this->assertJobType($jobType);
        $this->assertQueue($queue);
        $id = Uuid::uuid7()->toString();
        $now = $this->clock->now();
        $firstRun = $firstRun < $now ? $now : $firstRun;
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sql = sprintf(
            'INSERT INTO %s (id, name, cron_expression, timezone, queue, job_type, job_schema_version, payload, '
            . 'priority, maximum_attempts, enabled, next_run_at, version, created_at, updated_at) VALUES '
            . '(%s, %s, %s, %s, %s, %s, 1, %s::jsonb, 0, 5, true, %s, 1, %s, %s)',
            $this->table('schedules'),
            $this->quote($id),
            $this->quote($name),
            $this->quote($cronExpression),
            $this->quote($timezone),
            $this->quote($queue),
            $this->quote($jobType),
            $this->quote($json),
            $this->quote($this->timestamp($firstRun)),
            $this->quote($this->timestamp($now)),
            $this->quote($this->timestamp($now)),
        );
        $this->database->setQuery($sql)->execute();

        return $id;
    }

    public function all(): array
    {
        $rows = $this->database->setQuery(sprintf(
            'SELECT * FROM %s ORDER BY name',
            $this->table('schedules'),
        ))->loadAssocList();

        if (!is_array($rows)) {
            throw new RuntimeException('The schedule query returned an invalid result set.');
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function dispatch(array $row): void
    {
        $id = $this->requiredString($row, 'id');
        $scheduledFor = new DateTimeImmutable($this->requiredString($row, 'next_run_at'));
        $payload = $this->payload($row['payload'] ?? null);
        $occurrenceKey = (string) ScheduleOccurrenceKey::for($id, $scheduledFor);
        $now = $this->clock->now();
        $jobId = Uuid::uuid7()->toString();
        $insert = sprintf(
            'INSERT INTO %s (id, queue, job_type, schema_version, payload, priority, status, available_at, '
            . 'attempts, maximum_attempts, schedule_id, scheduled_for, occurrence_key, created_at, updated_at) '
            . "VALUES (%s, %s, %s, %d, %s::jsonb, %d, 'pending', %s, 0, %d, %s, %s, %s, %s, %s) "
            . 'ON CONFLICT (schedule_id, scheduled_for) WHERE schedule_id IS NOT NULL DO NOTHING',
            $this->table('jobs'),
            $this->quote($jobId),
            $this->quote($this->requiredString($row, 'queue')),
            $this->quote($this->requiredString($row, 'job_type')),
            (int) ($row['job_schema_version'] ?? 1),
            $this->quote(json_encode($payload, JSON_THROW_ON_ERROR)),
            (int) ($row['priority'] ?? 0),
            $this->quote($this->timestamp($now)),
            (int) ($row['maximum_attempts'] ?? 5),
            $this->quote($id),
            $this->quote($this->timestamp($scheduledFor)),
            $this->quote($occurrenceKey),
            $this->quote($this->timestamp($now)),
            $this->quote($this->timestamp($now)),
        );
        $this->database->setQuery($insert)->execute();
        $next = (new CronExpression($this->requiredString($row, 'cron_expression')))->next(
            $scheduledFor,
            $this->requiredString($row, 'timezone'),
        );
        $update = sprintf(
            'UPDATE %s SET last_run_at = %s, next_run_at = %s, version = version + 1, updated_at = %s WHERE id = %s',
            $this->table('schedules'),
            $this->quote($this->timestamp($scheduledFor)),
            $this->quote($this->timestamp($next)),
            $this->quote($this->timestamp($now)),
            $this->quote($id),
        );
        $this->database->setQuery($update)->execute();
    }

    /** @return array<string, mixed> */
    private function payload(mixed $stored): array
    {
        try {
            $payload = is_string($stored) ? json_decode($stored, true, 64, JSON_THROW_ON_ERROR) : $stored;
        } catch (JsonException $exception) {
            throw new RuntimeException('A schedule payload contains invalid JSON.', 0, $exception);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('A schedule payload must be a JSON object.');
        }

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

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
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
