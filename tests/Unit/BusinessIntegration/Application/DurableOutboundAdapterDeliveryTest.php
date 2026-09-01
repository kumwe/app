<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Automation\JitterSource;
use Kumwe\App\Application\Automation\RetryPolicy;
use Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\InboxClaimResult;
use Kumwe\App\BusinessIntegration\Application\InboxDisposition;
use Kumwe\App\BusinessIntegration\Application\InboxLease;
use Kumwe\App\BusinessIntegration\Application\InboxStore;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\WebhookContributionDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

#[CoversClass(DurableOutboundAdapterDispatcher::class)]
/**
 * Proves a freshly claimed outbound delivery publishes exactly once and completes its receipt.
 *
 * @since  2.0.0
 */
final class DurableOutboundAdapterDeliveryTest extends TestCase
{
    /**
     * Prove a claimed lease drives the adapter publish and then completes the durable receipt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAClaimedDeliveryPublishesAndCompletesItsReceipt(): void
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
            'record-9',
            3,
            'correlation-9',
            'request-9',
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-9'],
        );
        $definition = new WebhookContributionDefinition(
            'acme.search-adapter',
            ['acme.record.changed'],
            [1],
            '1.0.0',
            'integration.default',
        );
        $lease = new InboxLease(
            new EventConsumerDefinition('acme.search-adapter', 'acme.record.changed', [1], '1.0.0'),
            $event,
            1,
            'integration-worker-1',
            Uuid::uuid7()->toString(),
            '7',
        );
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::once())->method('receive')
            ->willReturn(new InboxClaimResult(InboxDisposition::CLAIMED, $lease));
        $inbox->expects(self::once())->method('complete')->with($lease);
        $inbox->expects(self::never())->method('fail');
        $adapter = $this->createMock(IntegrationEventTransport::class);
        $adapter->expects(self::once())->method('publish')->with($definition, $event);
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
            new RetryPolicy(self::clock(), self::jitter()),
            self::createStub(TrustedRuntimeGenerationGuard::class),
            new NullLogger(),
        );

        self::assertSame(InboxDisposition::CLAIMED, $dispatcher->dispatch(
            $definition,
            $adapter,
            $event,
            'integration-worker-1',
            '7',
        ));
    }

    /**
     * Build a fixed clock for the retry policy.
     *
     * @return  ClockInterface  Clock pinned to one instant.
     *
     * @since   2.0.0
     */
    private static function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            /**
             * Report the pinned probe instant.
             *
             * @return  DateTimeImmutable  Fixed timestamp.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-10T10:00:00+00:00');
            }
        };
    }

    /**
     * Build a deterministic jitter source for the retry policy.
     *
     * @return  JitterSource  Jitter that always answers the minimum.
     *
     * @since   2.0.0
     */
    private static function jitter(): JitterSource
    {
        return new class implements JitterSource {
            /**
             * Answer the minimum of the requested range.
             *
             * @param   int  $minimum  Lower bound.
             * @param   int  $maximum  Upper bound.
             *
             * @return  int  The lower bound.
             *
             * @since   2.0.0
             */
            public function between(int $minimum, int $maximum): int
            {
                unset($maximum);

                return $minimum;
            }
        };
    }
}
