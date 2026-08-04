<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Automation;

use DateTimeImmutable;
use Kumwe\CMS\Application\Automation\AutomationManagementService;
use Kumwe\CMS\Application\Automation\Job\DoctrineJobQueue;
use Kumwe\CMS\Application\Automation\Job\DoctrineScheduler;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\Kernel\ContainerFactory;
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
