<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration;

use DateTimeImmutable;
use Kumwe\App\Application\Automation\JitterSource;
use Kumwe\App\Application\Automation\QueueRuntimePolicy;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\Application\Automation\RetryPolicy;
use Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\InboxClaimResult;
use Kumwe\App\BusinessIntegration\Application\InboxDisposition;
use Kumwe\App\BusinessIntegration\Application\InboxStore;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\ConsumerIdempotency;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\WebhookContributionDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

#[CoversClass(DurableOutboundAdapterDispatcher::class)]
final class DurableOutboundAdapterDispatcherTest extends TestCase
{
    public function testAggregateVersionIdempotencyCompilesToAnOrderedDurableReceipt(): void
    {
        $event = new RecordedIntegrationEvent(
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
            2,
            'correlation-1',
            'request-1',
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-7'],
        );
        $definition = new WebhookContributionDefinition(
            'acme.search-adapter',
            ['acme.record.changed'],
            [1],
            '1.0.0',
            'integration.default',
            ConsumerIdempotency::AGGREGATE_VERSION,
        );
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::once())->method('receive')->with(
            self::callback(static fn (EventConsumerDefinition $receipt): bool => $receipt->aggregateOrdered()
                && $receipt->idempotency() === ConsumerIdempotency::AGGREGATE_VERSION),
            $event,
            'integration-worker-1',
            '7',
            60,
        )->willReturn(new InboxClaimResult(InboxDisposition::DUPLICATE));
        $adapter = $this->createStub(IntegrationEventTransport::class);
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

        $dispatcher = new DurableOutboundAdapterDispatcher(
            $inbox,
            $contracts,
            new RetryPolicy(
                new class implements ClockInterface {
                    public function now(): DateTimeImmutable
                    {
                        return new DateTimeImmutable('2026-08-10T10:00:00+00:00');
                    }
                },
                new class implements JitterSource {
                    public function between(int $minimum, int $maximum): int
                    {
                        return $minimum;
                    }
                },
            ),
            $this->createStub(TrustedRuntimeGenerationGuard::class),
            new NullLogger(),
        );

        self::assertSame(InboxDisposition::DUPLICATE, $dispatcher->dispatch(
            $definition,
            $adapter,
            $event,
            'integration-worker-1',
            '7',
        ));
    }

    public function testQueueLeaseDefaultsAndSensitivityRejectionUseTheDurableReceipt(): void
    {
        $event = new RecordedIntegrationEvent(
            'acme.record.changed',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            null,
            'worker',
            'default',
            null,
            'acme.record',
            'record-8',
            1,
            'correlation-2',
            'request-2',
            EventSensitivity::SECRET,
            ['record_id' => 'record-8'],
        );
        $definition = new WebhookContributionDefinition(
            'acme.restricted-adapter',
            ['acme.record.changed'],
            [1],
            '1.0.0',
            'integration.default',
            sensitivityCeiling: EventSensitivity::INTERNAL,
        );
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::once())->method('receive')->with(
            self::callback(static fn (EventConsumerDefinition $receipt): bool =>
                $receipt->sensitivityCeiling() === EventSensitivity::INTERNAL),
            $event,
            'integration-worker-1',
            '7',
            30,
        )->willReturn(new InboxClaimResult(InboxDisposition::UNAVAILABLE));
        $adapter = $this->createMock(IntegrationEventTransport::class);
        $adapter->expects(self::never())->method('publish');
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
        $dispatcher = new DurableOutboundAdapterDispatcher(
            $inbox,
            $contracts,
            new RetryPolicy(new OutboundDispatcherClock(), new OutboundDispatcherJitter()),
            $this->createStub(TrustedRuntimeGenerationGuard::class),
            new NullLogger(),
            new OutboundDispatcherQueueCatalog(new QueueRuntimePolicy(
                'integration.default',
                30,
                3,
                2,
                14,
                7,
            )),
        );

        self::assertSame(InboxDisposition::UNAVAILABLE, $dispatcher->dispatch(
            $definition,
            $adapter,
            $event,
            'integration-worker-1',
            '7',
        ));
    }
}

final readonly class OutboundDispatcherClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-10T10:00:00+00:00');
    }
}

final readonly class OutboundDispatcherJitter implements JitterSource
{
    public function between(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}

final readonly class OutboundDispatcherQueueCatalog implements QueueRuntimePolicyCatalog
{
    public function __construct(private QueueRuntimePolicy $policy)
    {
    }

    public function policy(string $queue): ?QueueRuntimePolicy
    {
        return $queue === $this->policy->queue ? $this->policy : null;
    }

    public function maximumAttempts(string $queue, string $jobType, int $requested): int
    {
        return $this->policy($queue) === null ? $requested : min($requested, $this->policy->maximumAttempts);
    }

    public function policies(): array
    {
        return [$this->policy];
    }
}
