<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Automation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Joomla\DI\Container;
use Kumwe\CMS\Application\Automation\JobExecutionClass;
use Kumwe\CMS\Application\Automation\ScheduleOccurrenceKey;
use Kumwe\CMS\Application\Automation\Scheduler;
use Kumwe\CMS\Infrastructure\Automation\DoctrineScheduler;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessRecordIdempotencyRetentionMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves the scheduler's duplicate-occurrence suppression on the configured engine.
 *
 * `DoctrineScheduler::dispatch()` swallows the unique violation another scheduler's identical occurrence
 * raises, and then still advances the schedule row past that occurrence. Both halves matter: without the
 * swallow a second scheduler crashes the pass, and without the advance the schedule re-fires the same
 * occurrence forever. Neither half had a test, and the swallow is engine-sensitive in a way a SQLite
 * suite would never reveal.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineScheduler::class)]
#[CoversClass(ScheduleOccurrenceKey::class)]
final class SchedulerOccurrenceContentionIntegrationTest extends TestCase
{
    public function testAnOccurrenceAnotherSchedulerAlreadyEmittedIsSwallowedAndTheScheduleStillAdvances(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $context = TestKernelFactory::schedulerContext($container);
        $scheduler = $container->get(Scheduler::class);
        self::assertInstanceOf(Scheduler::class, $scheduler);

        [$scheduleId, $due] = $this->makeDue($container);
        $occurrenceKey = (string) ScheduleOccurrenceKey::for($scheduleId, $due);
        $this->emitRival($container, $scheduleId, $due, $occurrenceKey);

        $dispatched = $scheduler->dispatchDue($context, 100);
        self::assertGreaterThanOrEqual(1, $dispatched, 'The due schedule was visited by this pass.');

        self::assertSame(
            1,
            (int) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE occurrence_key = ?',
                $tables->quoted('jobs'),
            ), [$occurrenceKey]),
            'The occurrence the rival scheduler already emitted was not enqueued a second time.',
        );

        $row = $database->fetchAssociative(sprintf(
            'SELECT last_run_at, next_run_at FROM %s WHERE id = ?',
            $tables->quoted('schedules'),
        ), [$scheduleId], [Types::GUID]);
        self::assertIsArray($row);
        self::assertSame(
            $due->format('Y-m-d H:i:s'),
            $this->instant($row['last_run_at'] ?? null)->format('Y-m-d H:i:s'),
            'The schedule recorded the occurrence it swallowed as run.',
        );
        self::assertGreaterThan(
            $due->getTimestamp(),
            $this->instant($row['next_run_at'] ?? null)->getTimestamp(),
            'The schedule advanced past the swallowed occurrence instead of re-firing it forever.',
        );
    }

    public function testASchedulePassSkipsARowAnotherSchedulerIsHoldingInsteadOfBlockingOnIt(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $database = $this->connection($primary);
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'SQLite parses no FOR UPDATE SKIP LOCKED on the schedule claim, so a second pass cannot be '
                . 'observed routing around a held schedule and the assertion would pass vacuously.',
            );
        }

        $secondary = TestKernelFactory::create($environment);
        $concurrent = $this->connection($secondary);
        $rivalContext = TestKernelFactory::schedulerContext($secondary);
        $rival = $secondary->get(Scheduler::class);
        self::assertInstanceOf(Scheduler::class, $rival);
        $this->boundLockWait($concurrent);

        [$scheduleId, $due] = $this->makeDue($primary);
        $occurrenceKey = (string) ScheduleOccurrenceKey::for($scheduleId, $due);

        try {
            $database->beginTransaction();
            self::assertSame($scheduleId, $database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE id = ? FOR UPDATE',
                $this->tables($primary)->quoted('schedules'),
            ), [$scheduleId], [Types::GUID]));

            $rival->dispatchDue($rivalContext, 100);
            self::assertSame(
                0,
                (int) $concurrent->fetchOne(sprintf(
                    'SELECT COUNT(*) FROM %s WHERE occurrence_key = ?',
                    $this->tables($secondary)->quoted('jobs'),
                ), [$occurrenceKey]),
                'The held schedule was skipped rather than dispatched by the rival pass.',
            );
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $concurrent->close();
        }
    }

    /**
     * Make the installation-wide retention schedule due in the past and report its due instant.
     *
     * @return  array{0: string, 1: DateTimeImmutable}  Schedule UUID and the occurrence instant it owes.
     */
    private function makeDue(Container $container): array
    {
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $scheduleId = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE job_type = ?',
            $tables->quoted('schedules'),
        ), [BusinessRecordIdempotencyRetentionMigration::JOB_TYPE]);
        if (!is_string($scheduleId)) {
            throw new RuntimeException('The installation retention schedule is not installed.');
        }
        $due = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-1 hour')
            ->setTime(
                (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 hour')->format('H'),
                43,
                0,
            );
        $database->executeStatement(sprintf(
            'UPDATE %s SET next_run_at = ?, last_run_at = NULL, enabled = ? WHERE id = ?',
            $tables->quoted('schedules'),
        ), [$due, true, $scheduleId], [Types::DATETIME_IMMUTABLE, Types::BOOLEAN, Types::GUID]);
        $database->executeStatement(sprintf(
            'DELETE FROM %s WHERE schedule_id = ?',
            $tables->quoted('jobs'),
        ), [$scheduleId], [Types::GUID]);

        return [$scheduleId, $due];
    }

    /**
     * Stand in for the rival scheduler that got to this occurrence first.
     */
    private function emitRival(
        Container $container,
        string $scheduleId,
        DateTimeImmutable $due,
        string $occurrenceKey,
    ): void {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->connection($container)->insert($this->tables($container)->raw('jobs'), [
            'id' => Uuid::uuid7()->toString(),
            'queue' => 'default',
            'job_type' => BusinessRecordIdempotencyRetentionMigration::JOB_TYPE,
            'execution_scope' => JobExecutionClass::Installation->value,
            'schema_version' => 1,
            'payload' => ['batch_size' => 500, 'maximum_batches' => 10],
            'priority' => -10,
            'status' => 'pending',
            'available_at' => $now,
            'attempts' => 0,
            'maximum_attempts' => 5,
            'schedule_id' => $scheduleId,
            'scheduled_for' => $due,
            'occurrence_key' => $occurrenceKey,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'id' => Types::GUID,
            'payload' => Types::JSON,
            'available_at' => Types::DATETIME_IMMUTABLE,
            'schedule_id' => Types::GUID,
            'scheduled_for' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private function instant(mixed $stored): DateTimeImmutable
    {
        if ($stored instanceof DateTimeImmutable) {
            return $stored;
        }
        if (!is_string($stored)) {
            throw new RuntimeException('A schedule timestamp column is not readable.');
        }

        return new DateTimeImmutable($stored, new DateTimeZone('UTC'));
    }

    private function boundLockWait(Connection $connection): void
    {
        $connection->executeStatement(
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '500ms'",
        );
    }

    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }

    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
    }
}
