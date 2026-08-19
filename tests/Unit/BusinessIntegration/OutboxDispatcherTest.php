<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration;

use DateTimeImmutable;
use Kumwe\App\Application\Automation\FailureClassification;
use Kumwe\App\Application\Automation\JitterSource;
use Kumwe\App\Application\Automation\RetryPolicy;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\IntegrationDeliveryBackpressure;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\App\BusinessIntegration\Application\OutboxDispatcher;
use Kumwe\App\BusinessIntegration\Application\OutboxLease;
use Kumwe\App\BusinessIntegration\Application\OutboxStore;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\App\BusinessIntegration\Domain\IntegrationEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
use Throwable;

#[CoversClass(OutboxDispatcher::class)]
#[CoversClass(IntegrationDeliveryBackpressure::class)]
final class OutboxDispatcherTest extends TestCase
{
    public function testQueueBackpressureDefersWithoutRecordingAFailedAttempt(): void
    {
        $event = new IntegrationEvent(
            'acme.record.changed',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            null,
            'worker',
            'default',
            null,
            'acme.record',
            'record-7',
            1,
            'correlation-1',
            'request-1',
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-7'],
        );
        $outbox = new BackpressuredOutboxStore(new OutboxLease(
            $event,
            1,
            1,
            'outbox-worker-1',
            Uuid::uuid7()->toString(),
            '7',
        ));
        $contracts = new EventContractRegistry([new EventSchemaDefinition(
            'acme.record.changed',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        )], []);
        $logger = new TestLogger();
        $dispatcher = new OutboxDispatcher(
            $outbox,
            $contracts,
            new AlwaysBackpressuredTransport(),
            new RetryPolicy(new OutboxDispatcherClock(), new OutboxDispatcherJitter()),
            new OutboxDispatcherRuntime(),
            $logger,
        );

        self::assertTrue($dispatcher->dispatchOne('outbox-worker-1', '7'));
        self::assertSame(5, $outbox->deferredSeconds);
        self::assertFalse($outbox->completed);
        self::assertFalse($outbox->failed);
        // Without these two keys, stitching one business operation across HTTP, outbox and consumer
        // logs means joining against database rows instead of grepping the log stream.
        self::assertCount(1, $logger->records);
        self::assertSame('correlation-1', $logger->records[0]['context']['correlation_id'] ?? null);
        self::assertSame('request-1', $logger->records[0]['context']['causation_id'] ?? null);
    }
}

final class TestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

final class BackpressuredOutboxStore implements OutboxStore
{
    public ?int $deferredSeconds = null;

    public bool $completed = false;

    public bool $failed = false;

    public function __construct(private OutboxLease $lease)
    {
    }

    public function append(
        IntegrationEvent $event,
        int $maximumAttempts = 10,
        ?DateTimeImmutable $availableAt = null,
    ): void {
    }

    public function claim(string $workerId, string $runtimeGeneration, int $leaseSeconds): ?OutboxLease
    {
        return $this->lease;
    }

    public function renew(OutboxLease $lease, int $leaseSeconds): void
    {
    }

    public function complete(OutboxLease $lease): void
    {
        $this->completed = true;
    }

    public function defer(OutboxLease $lease, int $delaySeconds = 5): void
    {
        $this->deferredSeconds = $delaySeconds;
    }

    public function fail(
        OutboxLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void {
        $this->failed = true;
    }

    public function replay(string $eventId, string $operatorId, ?DateTimeImmutable $availableAt = null): void
    {
    }

    public function purgeExpired(DateTimeImmutable $now, int $limit = 1_000): int
    {
        return 0;
    }

    public function recent(int $limit = 100): array
    {
        return [];
    }
}

final readonly class AlwaysBackpressuredTransport implements IntegrationEventTransport
{
    public function identifier(): string
    {
        return 'acme.backpressured';
    }

    public function sensitivityCeiling(): EventSensitivity
    {
        return EventSensitivity::SECRET;
    }

    public function publish(IntegrationEvent $event): void
    {
        throw new IntegrationDeliveryBackpressure('The contributed queue is at capacity.');
    }
}

final readonly class OutboxDispatcherRuntime implements TrustedRuntimeGenerationGuard
{
    public function assertCurrent(string $generation): void
    {
    }
}

final readonly class OutboxDispatcherClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-10T10:00:00+00:00');
    }
}

final readonly class OutboxDispatcherJitter implements JitterSource
{
    public function between(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}
