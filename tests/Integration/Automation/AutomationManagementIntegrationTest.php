<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Automation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Automation\AutomationManagementService;
use Kumwe\CMS\Application\Automation\Job\DoctrineJobQueue;
use Kumwe\CMS\Application\Automation\Job\DoctrineScheduler;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(AutomationManagementService::class)]
#[CoversClass(DoctrineJobQueue::class)]
#[CoversClass(DoctrineScheduler::class)]
final class AutomationManagementIntegrationTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testScheduleAndJobManagementLifecycleOnConfiguredDatabase(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $automation = $container->get(AutomationManagementService::class);
        $queue = $container->get(JobQueue::class);
        self::assertInstanceOf(AutomationManagementService::class, $automation);
        self::assertInstanceOf(JobQueue::class, $queue);
        $now = new DateTimeImmutable('now');
        $scheduleId = $automation->createSchedule(
            self::ACTOR,
            'Test schedule ' . Uuid::uuid7()->toString(),
            '*/15 * * * *',
            'UTC',
            'system.sessions.purge',
            [],
            'default',
            $now,
        );
        $schedule = $automation->schedule($scheduleId);

        self::assertTrue($schedule['enabled']);
        self::assertSame(1, $schedule['version']);
        $automation->setScheduleEnabled(self::ACTOR, $scheduleId, 1, false);
        $disabled = $automation->schedule($scheduleId);
        self::assertFalse($disabled['enabled']);
        self::assertSame(2, $disabled['version']);
        $automation->deleteSchedule(self::ACTOR, $scheduleId, 2);

        $jobId = $queue->enqueue('system.sessions.purge', [], $now);
        $queue->heartbeat('integration-worker', 'default');
        $job = $queue->claim('default', 'integration-worker', 60);
        self::assertNotNull($job);
        self::assertSame($jobId, $job->id);
        $queue->fail($job, 'integration-worker', new RuntimeException('Expected test failure.'), true);
        $dead = $this->job($automation, $jobId);
        self::assertSame('dead', $dead['status']);
        self::assertSame('Expected test failure.', $dead['error_message']);

        $automation->retryJob(self::ACTOR, $jobId);
        $retried = $this->job($automation, $jobId);
        self::assertSame('pending', $retried['status']);
        self::assertSame(0, $retried['attempts']);
        self::assertNull($retried['error_message']);
        $automation->cancelJob(self::ACTOR, $jobId);
        self::assertSame('canceled', $this->job($automation, $jobId)['status']);
    }

    public function testExpiredReservationsAreReclaimedFencedAndDeadLetteredAtAttemptLimit(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $queue = $container->get(JobQueue::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(JobQueue::class, $queue);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $queueName = 'recovery-' . substr(Uuid::uuid7()->toString(), 0, 12);
        $jobId = $queue->enqueue(
            'system.sessions.purge',
            [],
            new DateTimeImmutable('now'),
            $queueName,
            maximumAttempts: 2,
        );

        $first = $queue->claim($queueName, 'recovery-worker-one', 60);
        self::assertNotNull($first);
        $this->expireLease($database, $tables, $jobId);
        $second = $queue->claim($queueName, 'recovery-worker-two', 60);
        self::assertNotNull($second);
        self::assertSame(2, $second->attempts);
        self::assertNotSame($first->leaseToken, $second->leaseToken);

        try {
            $queue->complete($first, 'recovery-worker-one');
            self::fail('A stale lease owner completed a reassigned job.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('no longer owns', $exception->getMessage());
        }

        $queue->renew($second, 'recovery-worker-two', 120);
        self::assertSame($second->leaseToken, $database->fetchOne(sprintf(
            'SELECT lease_token FROM %s WHERE id = ?',
            $tables->quoted('jobs'),
        ), [$jobId]));

        $this->expireLease($database, $tables, $jobId);
        self::assertNull($queue->claim($queueName, 'recovery-worker-three', 60));
        $dead = $this->job($container->get(AutomationManagementService::class), $jobId);
        self::assertSame('dead', $dead['status']);
        self::assertSame('transient', $dead['failure_classification']);
        self::assertStringContainsString('final worker lease expired', (string) $dead['error_message']);
    }

    public function testExhaustedBacklogCleanupIsBoundedPerClaimTransaction(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $queue = $container->get(JobQueue::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(JobQueue::class, $queue);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $queueName = 'exhausted-' . substr(Uuid::uuid7()->toString(), 0, 12);
        $backlog = DoctrineJobQueue::EXHAUSTED_REAP_LIMIT + 1;
        $now = new DateTimeImmutable('now');
        for ($index = 0; $index < $backlog; $index++) {
            $queue->enqueue(
                'system.sessions.purge',
                ['index' => $index],
                $now,
                $queueName,
                maximumAttempts: 1,
            );
        }
        $database->executeStatement(sprintf(
            'UPDATE %s SET attempts = maximum_attempts WHERE queue = ? AND status = ?',
            $tables->quoted('jobs'),
        ), [$queueName, 'pending']);

        self::assertNull($queue->claim($queueName, 'backlog-worker-one', 60));
        self::assertSame(
            DoctrineJobQueue::EXHAUSTED_REAP_LIMIT,
            $this->jobCount($database, $tables, $queueName, 'dead'),
        );
        self::assertSame(1, $this->jobCount($database, $tables, $queueName, 'pending'));
        self::assertSame(
            DoctrineJobQueue::EXHAUSTED_REAP_LIMIT,
            $this->failedJobCount($database, $tables, $queueName),
        );

        self::assertNull($queue->claim($queueName, 'backlog-worker-two', 60));
        self::assertSame($backlog, $this->jobCount($database, $tables, $queueName, 'dead'));
        self::assertSame(0, $this->jobCount($database, $tables, $queueName, 'pending'));
        self::assertSame($backlog, $this->failedJobCount($database, $tables, $queueName));
    }

    private function jobCount(
        Connection $database,
        TableNames $tables,
        string $queue,
        string $status,
    ): int {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE queue = ? AND status = ?',
            $tables->quoted('jobs'),
        ), [$queue, $status]);
    }

    private function failedJobCount(Connection $database, TableNames $tables, string $queue): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s f INNER JOIN %s j ON j.id = f.job_id WHERE j.queue = ?',
            $tables->quoted('failed_jobs'),
            $tables->quoted('jobs'),
        ), [$queue]);
    }

    private function expireLease(Connection $database, TableNames $tables, string $jobId): void
    {
        $database->update(
            $tables->raw('jobs'),
            ['lease_expires_at' => new DateTimeImmutable('-1 second')],
            ['id' => $jobId],
            ['lease_expires_at' => Types::DATETIME_IMMUTABLE, 'id' => Types::GUID],
        );
    }

    /** @return array<string, mixed> */
    private function job(AutomationManagementService $automation, string $id): array
    {
        foreach ($automation->jobs(500) as $job) {
            if (($job['id'] ?? null) === $id) {
                return $job;
            }
        }

        self::fail(sprintf('Automation job %s was not returned by the management query.', $id));
    }
}
