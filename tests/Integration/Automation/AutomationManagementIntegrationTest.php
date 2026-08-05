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
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnership;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
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
    public function testScheduleAndJobManagementLifecycleOnConfiguredDatabase(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $automation = $container->get(AutomationManagementService::class);
        $queue = $container->get(JobQueue::class);
        $ownership = $container->get(ResourceSiteOwnership::class);
        self::assertInstanceOf(AutomationManagementService::class, $automation);
        self::assertInstanceOf(JobQueue::class, $queue);
        self::assertInstanceOf(ResourceSiteOwnership::class, $ownership);
        $now = new DateTimeImmutable('now');
        $context = TestKernelFactory::administratorContext($container);
        $scheduleId = $automation->createSchedule(
            $context,
            'Test schedule ' . Uuid::uuid7()->toString(),
            '*/15 * * * *',
            'UTC',
            'system.sessions.purge',
            [],
            'default',
            $now,
        );
        $schedule = $automation->schedule($context, $scheduleId);

        self::assertTrue($schedule['enabled']);
        self::assertSame(1, $schedule['version']);
        $automation->setScheduleEnabled($context, $scheduleId, 1, false);
        $disabled = $automation->schedule($context, $scheduleId);
        self::assertFalse($disabled['enabled']);
        self::assertSame(2, $disabled['version']);
        $automation->deleteSchedule($context, $scheduleId, 2);
        try {
            $ownership->siteFor(AuthorizationResource::item('schedule', $scheduleId));
            self::fail('A deleted schedule cannot leave an authorization ownership tombstone.');
        } catch (AuthorizationResourceOwnershipUnknown) {
            self::addToAssertionCount(1);
        }

        $jobId = $queue->enqueue($context, 'system.sessions.purge', [], $now);
        $automation->cancelJob($context, $jobId);
        self::assertSame('canceled', $this->job($automation, $context, $jobId)['status']);
    }

    public function testExpiredReservationsAreReclaimedFencedAndDeadLetteredAtAttemptLimit(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $queue = $container->get(JobQueue::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(JobQueue::class, $queue);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $administratorContext = TestKernelFactory::administratorContext($container);
        $workerContext = TestKernelFactory::workerContext($container);
        $queueName = 'recovery-' . substr(Uuid::uuid7()->toString(), 0, 12);
        $jobId = $queue->enqueue(
            $administratorContext,
            'system.sessions.purge',
            [],
            new DateTimeImmutable('now'),
            $queueName,
            maximumAttempts: 2,
        );

        $first = $queue->claim($workerContext, $queueName, 'recovery-worker-one', 60);
        self::assertNotNull($first);
        $this->expireLease($database, $tables, $jobId);
        $second = $queue->claim($workerContext, $queueName, 'recovery-worker-two', 60);
        self::assertNotNull($second);
        self::assertSame(2, $second->attempts);
        self::assertNotSame($first->leaseToken, $second->leaseToken);

        try {
            $queue->complete($workerContext, $first, 'recovery-worker-one');
            self::fail('A stale lease owner completed a reassigned job.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('no longer owns', $exception->getMessage());
        }

        $queue->renew($workerContext, $second, 'recovery-worker-two', 120);
        self::assertSame($second->leaseToken, $database->fetchOne(sprintf(
            'SELECT lease_token FROM %s WHERE id = ?',
            $tables->quoted('jobs'),
        ), [$jobId]));

        $this->expireLease($database, $tables, $jobId);
        self::assertNull($queue->claim($workerContext, $queueName, 'recovery-worker-three', 60));
        $dead = $this->job(
            $container->get(AutomationManagementService::class),
            $administratorContext,
            $jobId,
        );
        self::assertSame('dead', $dead['status']);
        self::assertSame('transient', $dead['failure_classification']);
        self::assertStringContainsString('final worker lease expired', (string) $dead['error_message']);
    }

    public function testExhaustedBacklogCleanupIsBoundedPerClaimTransaction(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $queue = $container->get(JobQueue::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(JobQueue::class, $queue);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $administratorContext = TestKernelFactory::administratorContext($container);
        $workerContext = TestKernelFactory::workerContext($container);
        $queueName = 'exhausted-' . substr(Uuid::uuid7()->toString(), 0, 12);
        $backlog = DoctrineJobQueue::EXHAUSTED_REAP_LIMIT + 1;
        $now = new DateTimeImmutable('now');
        for ($index = 0; $index < $backlog; $index++) {
            $queue->enqueue(
                $administratorContext,
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

        self::assertNull($queue->claim($workerContext, $queueName, 'backlog-worker-one', 60));
        self::assertSame(
            DoctrineJobQueue::EXHAUSTED_REAP_LIMIT,
            $this->jobCount($database, $tables, $queueName, 'dead'),
        );
        self::assertSame(1, $this->jobCount($database, $tables, $queueName, 'pending'));
        self::assertSame(
            DoctrineJobQueue::EXHAUSTED_REAP_LIMIT,
            $this->failedJobCount($database, $tables, $queueName),
        );

        self::assertNull($queue->claim($workerContext, $queueName, 'backlog-worker-two', 60));
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
    private function job(AutomationManagementService $automation, ExecutionContext $context, string $id): array
    {
        foreach ($automation->jobs($context, 500) as $job) {
            if (($job['id'] ?? null) === $id) {
                return $job;
            }
        }

        self::fail(sprintf('Automation job %s was not returned by the management query.', $id));
    }
}
