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
use Kumwe\CMS\Application\Automation\Scheduler;
use Kumwe\CMS\Application\Automation\Worker;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
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

    public function testSchedulerPreservesTheDurableSiteOnDispatchedJobs(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $automation = $container->get(AutomationManagementService::class);
        $scheduler = $container->get(Scheduler::class);
        $ownership = $container->get(ResourceSiteOwnership::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(AutomationManagementService::class, $automation);
        self::assertInstanceOf(Scheduler::class, $scheduler);
        self::assertInstanceOf(ResourceSiteOwnership::class, $ownership);
        $site = $this->createSite($database, $tables);
        $context = $this->siteAutomationContext($container, $site);
        $queue = 'site-schedule-' . substr(Uuid::uuid7()->toString(), 0, 12);
        $scheduleId = $automation->createSchedule(
            $context,
            'Non-default site schedule ' . Uuid::uuid7()->toString(),
            '*/15 * * * *',
            'UTC',
            'system.sessions.purge',
            [],
            $queue,
            new DateTimeImmutable('-1 minute'),
        );

        self::assertGreaterThanOrEqual(1, $scheduler->dispatchDue(TestKernelFactory::schedulerContext($container)));
        $jobId = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE schedule_id = ? ORDER BY created_at DESC',
            $tables->quoted('jobs'),
        ), [$scheduleId]);
        self::assertIsString($jobId);
        self::assertSame(
            $site,
            $ownership->siteFor(AuthorizationResource::item('job', $jobId))->identifier(),
        );
    }

    public function testWorkerExecutesAndCompletesAJobInItsDurablyOwnedSite(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $queue = $container->get(JobQueue::class);
        $worker = $container->get(Worker::class);
        $ownership = $container->get(ResourceSiteOwnership::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(JobQueue::class, $queue);
        self::assertInstanceOf(Worker::class, $worker);
        self::assertInstanceOf(ResourceSiteOwnership::class, $ownership);
        $site = $this->createSite($database, $tables);
        $context = $this->siteAutomationContext($container, $site);
        $queueName = 'site-worker-' . substr(Uuid::uuid7()->toString(), 0, 12);
        $jobId = $queue->enqueue(
            $context,
            'system.sessions.purge',
            [],
            new DateTimeImmutable(),
            $queueName,
        );

        self::assertTrue($worker->runOnce(
            TestKernelFactory::workerContext($container),
            $queueName,
            'site-worker-integration',
        ));
        self::assertSame('completed', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE id = ?',
            $tables->quoted('jobs'),
        ), [$jobId]));
        self::assertSame(
            $site,
            $ownership->siteFor(AuthorizationResource::item('job', $jobId))->identifier(),
        );
    }

    public function testDisabledOrRemovedSiteJobsAndSchedulesAreNotClaimedOrDispatched(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $automation = $container->get(AutomationManagementService::class);
        $queue = $container->get(JobQueue::class);
        $scheduler = $container->get(Scheduler::class);
        $worker = $container->get(Worker::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(AutomationManagementService::class, $automation);
        self::assertInstanceOf(JobQueue::class, $queue);
        self::assertInstanceOf(Scheduler::class, $scheduler);
        self::assertInstanceOf(Worker::class, $worker);
        $site = $this->createSite($database, $tables);
        $context = $this->siteAutomationContext($container, $site);
        $queueName = 'retired-site-' . substr(Uuid::uuid7()->toString(), 0, 12);
        $jobId = $queue->enqueue(
            $context,
            'system.sessions.purge',
            [],
            new DateTimeImmutable('-1 minute'),
            $queueName,
        );
        $scheduleId = $automation->createSchedule(
            $context,
            'Retired site schedule ' . Uuid::uuid7()->toString(),
            '*/15 * * * *',
            'UTC',
            'system.sessions.purge',
            [],
            $queueName,
            new DateTimeImmutable('-1 minute'),
        );
        $database->update(
            $tables->raw('sites'),
            ['enabled' => false],
            ['identifier' => $site],
            ['enabled' => Types::BOOLEAN],
        );

        self::assertFalse($worker->runOnce(
            TestKernelFactory::workerContext($container),
            $queueName,
            'disabled-site-worker',
        ));
        $scheduler->dispatchDue(TestKernelFactory::schedulerContext($container));

        self::assertSame('pending', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE id = ?',
            $tables->quoted('jobs'),
        ), [$jobId]));
        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE schedule_id = ?',
            $tables->quoted('jobs'),
        ), [$scheduleId]));

        $database->delete($tables->raw('sites'), ['identifier' => $site]);
        self::assertFalse($worker->runOnce(
            TestKernelFactory::workerContext($container),
            $queueName,
            'retired-site-worker',
        ));
        $scheduler->dispatchDue(TestKernelFactory::schedulerContext($container));
        self::assertSame('pending', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE id = ?',
            $tables->quoted('jobs'),
        ), [$jobId]));
        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE schedule_id = ?',
            $tables->quoted('jobs'),
        ), [$scheduleId]));
    }

    public function testSiteScopedAutomationCannotDelegateInstallationWideJobs(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $automation = $container->get(AutomationManagementService::class);
        $queue = $container->get(JobQueue::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(AutomationManagementService::class, $automation);
        self::assertInstanceOf(JobQueue::class, $queue);
        $site = $this->createSite($database, $tables);
        $context = $this->siteAutomationContext($container, $site);

        try {
            $queue->enqueue(
                $context,
                'extensions.runtime.rebuild',
                [],
                new DateTimeImmutable(),
            );
            self::fail('A site-scoped manager enqueued an installation-wide job.');
        } catch (AuthorizationDenied $denied) {
            self::assertSame('global_grant_required', $denied->reason);
        }

        try {
            $automation->createSchedule(
                $context,
                'Denied installation maintenance ' . Uuid::uuid7()->toString(),
                '*/15 * * * *',
                'UTC',
                'system.idempotency.purge',
                [],
                'default',
                new DateTimeImmutable(),
            );
            self::fail('A site-scoped manager scheduled installation-wide maintenance.');
        } catch (AuthorizationDenied $denied) {
            self::assertSame('global_grant_required', $denied->reason);
        }
    }

    public function testInstallationGlobalWorkSurvivesDeletedOwnerAndDisabledDefaultSite(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $automation = $container->get(AutomationManagementService::class);
        $queue = $container->get(JobQueue::class);
        $scheduler = $container->get(Scheduler::class);
        $worker = $container->get(Worker::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(AutomationManagementService::class, $automation);
        self::assertInstanceOf(JobQueue::class, $queue);
        self::assertInstanceOf(Scheduler::class, $scheduler);
        self::assertInstanceOf(Worker::class, $worker);

        $selectedSite = $this->createSite($database, $tables);
        $global = TestKernelFactory::contextFromGrantRows($container, [[
            'capability' => 'automation.manage',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]], $selectedSite);
        $directQueue = 'global-direct-' . substr(Uuid::uuid7()->toString(), 0, 12);
        $scheduledQueue = 'global-scheduled-' . substr(Uuid::uuid7()->toString(), 0, 12);
        $directJob = $queue->enqueue(
            $global,
            'system.idempotency.purge',
            ['batch_size' => 10, 'maximum_batches' => 1],
            new DateTimeImmutable('-1 minute'),
            $directQueue,
        );
        $schedule = $automation->createSchedule(
            $global,
            'Installation purge ' . Uuid::uuid7()->toString(),
            '*/15 * * * *',
            'UTC',
            'system.idempotency.purge',
            ['batch_size' => 10, 'maximum_batches' => 1],
            $scheduledQueue,
            new DateTimeImmutable('-1 minute'),
        );

        self::assertSame('installation', $database->fetchOne(sprintf(
            'SELECT execution_scope FROM %s WHERE id = ?',
            $tables->quoted('jobs'),
        ), [$directJob]));
        self::assertSame('installation', $database->fetchOne(sprintf(
            'SELECT execution_scope FROM %s WHERE id = ?',
            $tables->quoted('schedules'),
        ), [$schedule]));
        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE (resource_type = ? AND resource_id = ?) '
            . 'OR (resource_type = ? AND resource_id = ?)',
            $tables->quoted('resource_site_ownership'),
        ), ['job', $directJob, 'schedule', $schedule]));

        $database->delete($tables->raw('sites'), ['identifier' => $selectedSite]);
        $database->update(
            $tables->raw('sites'),
            ['enabled' => false],
            ['identifier' => 'default'],
            ['enabled' => Types::BOOLEAN],
        );
        try {
            self::assertGreaterThanOrEqual(1, $scheduler->dispatchDue(
                TestKernelFactory::schedulerContext($container),
            ));
            self::assertTrue($worker->runOnce(
                TestKernelFactory::workerContext($container),
                $directQueue,
                'global-direct-worker',
            ));
            self::assertTrue($worker->runOnce(
                TestKernelFactory::workerContext($container),
                $scheduledQueue,
                'global-scheduled-worker',
            ));
        } finally {
            $database->update(
                $tables->raw('sites'),
                ['enabled' => true],
                ['identifier' => 'default'],
                ['enabled' => Types::BOOLEAN],
            );
        }

        self::assertSame('completed', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE id = ?',
            $tables->quoted('jobs'),
        ), [$directJob]));
        self::assertSame('completed', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE schedule_id = ?',
            $tables->quoted('jobs'),
        ), [$schedule]));
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

    private function createSite(Connection $database, TableNames $tables): string
    {
        $site = 'integration-' . substr(Uuid::uuid7()->toString(), 0, 18);
        $database->insert($tables->raw('sites'), [
            'identifier' => $site,
            'name' => 'Automation integration site',
            'created_at' => new DateTimeImmutable(),
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);

        return $site;
    }

    private function siteAutomationContext(\Joomla\DI\Container $container, string $site): ExecutionContext
    {
        return TestKernelFactory::contextFromGrantRows($container, [[
            'capability' => 'automation.manage',
            'scope_type' => 'site',
            'scope_identifier' => $site,
        ]], $site);
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
