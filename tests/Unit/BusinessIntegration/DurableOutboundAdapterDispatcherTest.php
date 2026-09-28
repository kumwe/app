<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration;

use DateTimeImmutable;
use Kumwe\Automation\JitterSource;
use Kumwe\Automation\QueueRuntimePolicy;
use Kumwe\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Automation\FailureClassification;
use Kumwe\Automation\PermanentFailure;
use Kumwe\Automation\RetryPolicy;
use Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\InboxClaimResult;
use Kumwe\Integration\InboxDisposition;
use Kumwe\Integration\InboxLease;
use Kumwe\Integration\InboxStore;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\Integration\ConsumerIdempotency;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\EventSchemaDefinition;
use Kumwe\Integration\EventSensitivity;
use Kumwe\Integration\IntegrationEvent;
use Kumwe\Integration\RecordedIntegrationEvent;
use Kumwe\Integration\WebhookContributionDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;

#[CoversClass(DurableOutboundAdapterDispatcher::class)]
final class DurableOutboundAdapterDispatcherTest extends TestCase
{
    public function testAggregateVersionIdempotencyCompilesToAnOrderedDurableReceipt(): void
    {
        $event = new RecordedIntegrationEvent(
            new DeterministicCanonicalEncoder(),
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
        $contracts = new EventContractRegistry(new DeterministicCanonicalEncoder(), [new EventSchemaDefinition(
            new DeterministicCanonicalEncoder(),
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
            new DeterministicCanonicalEncoder(),
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
        $contracts = new EventContractRegistry(new DeterministicCanonicalEncoder(), [new EventSchemaDefinition(
            new DeterministicCanonicalEncoder(),
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

    /**
     * Prove a claimed webhook lease that disagrees with its trusted declaration never reaches the adapter.
     *
     * Each case changes exactly one property the declaration pins: the adapter identity, the signed handler
     * revision, the declared event type, the accepted schema revision, or the sensitivity ceiling. The
     * refusal happens before any effect, so the receipt is neither completed nor failed.
     *
     * @param   string            $consumerId      Adapter identity recorded on the lease.
     * @param   string            $handlerVersion  Handler revision recorded on the lease.
     * @param   string            $eventType       Event type carried by the leased event.
     * @param   int               $schemaVersion   Schema revision carried by the leased event.
     * @param   EventSensitivity  $sensitivity     Classification carried by the leased event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('mismatchedLeases')]
    public function testAClaimedLeaseThatDisagreesWithItsDeclarationIsRefusedBeforeAnyEffect(
        string $consumerId,
        string $handlerVersion,
        string $eventType,
        int $schemaVersion,
        EventSensitivity $sensitivity,
    ): void {
        $event = self::claimedEvent($eventType, $schemaVersion, $sensitivity);
        $lease = new InboxLease(
            new EventConsumerDefinition($consumerId, $eventType, [1, 2], $handlerVersion, 'integration.default'),
            $event,
            1,
            'integration-worker-1',
            Uuid::uuid7()->toString(),
            '7',
        );
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::never())->method('complete');
        $inbox->expects(self::never())->method('fail');
        $adapter = $this->createMock(IntegrationEventTransport::class);
        $adapter->expects(self::never())->method('publish');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A webhook lease does not match its trusted declaration.');

        self::claimedDispatcher($inbox)->dispatchClaimed(self::claimedDefinition(), $adapter, $lease);
    }

    /**
     * Name each single-property divergence between a claimed lease and the trusted webhook declaration.
     *
     * @return  array<string, array{string, string, string, int, EventSensitivity}>  Lease identity, handler
     *          revision, event type, schema revision and event sensitivity, with exactly one out of line.
     *
     * @since   2.0.0
     */
    public static function mismatchedLeases(): array
    {
        return [
            'another adapter identity' => [
                'acme.other-adapter', '1.0.0', 'acme.record.changed', 1, EventSensitivity::INTERNAL,
            ],
            'another signed handler revision' => [
                'acme.search-adapter', '2.0.0', 'acme.record.changed', 1, EventSensitivity::INTERNAL,
            ],
            'an undeclared event type' => [
                'acme.search-adapter', '1.0.0', 'acme.record.deleted', 1, EventSensitivity::INTERNAL,
            ],
            'an unaccepted schema revision' => [
                'acme.search-adapter', '1.0.0', 'acme.record.changed', 2, EventSensitivity::INTERNAL,
            ],
            'a sensitivity above the declared ceiling' => [
                'acme.search-adapter', '1.0.0', 'acme.record.changed', 1, EventSensitivity::SECRET,
            ],
        ];
    }

    /**
     * Prove a matching lease is published once and then settled as completed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMatchingClaimedLeaseIsPublishedAndCompleted(): void
    {
        $definition = self::claimedDefinition();
        $lease = self::matchingLease();
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::once())->method('complete')->with($lease);
        $inbox->expects(self::never())->method('fail');
        $adapter = $this->createMock(IntegrationEventTransport::class);
        $adapter->expects(self::once())->method('publish')->with($definition, $lease->event);

        self::assertSame(
            InboxDisposition::CLAIMED,
            self::claimedDispatcher($inbox)->dispatchClaimed($definition, $adapter, $lease),
        );
    }

    /**
     * Prove an adapter failure is recorded against the lease with its retry decision and then rethrown.
     *
     * A transient failure within the attempt budget is rescheduled; a permanent failure is quarantined
     * with no retry time. Either way the receipt is never completed and the caller sees the original error.
     *
     * @param   Throwable              $failure         Failure raised by the outbound adapter.
     * @param   FailureClassification  $classification  Classification the retry policy must record.
     * @param   bool                   $retries         Whether the recorded decision carries a retry time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('adapterFailures')]
    public function testAnAdapterFailureIsRecordedWithItsRetryDecisionAndRethrown(
        Throwable $failure,
        FailureClassification $classification,
        bool $retries,
    ): void {
        $lease = self::matchingLease();
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::never())->method('complete');
        $inbox->expects(self::once())->method('fail')->with(
            $lease,
            $classification,
            $failure,
            $retries
                ? new DateTimeImmutable('2026-08-10T10:00:00+00:00')
                : null,
        );
        $adapter = $this->createStub(IntegrationEventTransport::class);
        $adapter->method('publish')->willThrowException($failure);

        try {
            self::claimedDispatcher($inbox)->dispatchClaimed(self::claimedDefinition(), $adapter, $lease);
            self::fail('A failed outbound delivery must surface to the worker.');
        } catch (Throwable $thrown) {
            self::assertSame($failure, $thrown);
        }
    }

    /**
     * Name the adapter failures whose classification decides between retry and quarantine.
     *
     * @return  array<string, array{Throwable, FailureClassification, bool}>  Failure, recorded class and
     *          whether a retry time is recorded.
     *
     * @since   2.0.0
     */
    public static function adapterFailures(): array
    {
        return [
            'transient endpoint outage' => [
                new RuntimeException('endpoint unavailable'), FailureClassification::TRANSIENT, true,
            ],
            'permanent rejection' => [
                new PermanentFailure('endpoint rejected the payload'), FailureClassification::PERMANENT, false,
            ],
        ];
    }

    /**
     * Build the trusted webhook declaration the claimed-lease cases are checked against.
     *
     * @return  WebhookContributionDefinition  Adapter accepting schema revision 1 of one event type.
     *
     * @since   2.0.0
     */
    private static function claimedDefinition(): WebhookContributionDefinition
    {
        return new WebhookContributionDefinition(
            'acme.search-adapter',
            ['acme.record.changed'],
            [1],
            '1.0.0',
            'integration.default',
            maximumAttempts: 3,
        );
    }

    /**
     * Build a lease that agrees with the trusted declaration in every pinned property.
     *
     * @return  InboxLease  First-attempt lease for the declared adapter and event.
     *
     * @since   2.0.0
     */
    private static function matchingLease(): InboxLease
    {
        return new InboxLease(
            new EventConsumerDefinition(
                'acme.search-adapter',
                'acme.record.changed',
                [1],
                '1.0.0',
                'integration.default',
                false,
                maximumAttempts: 3,
            ),
            self::claimedEvent('acme.record.changed', 1, EventSensitivity::INTERNAL),
            1,
            'integration-worker-1',
            Uuid::uuid7()->toString(),
            '7',
        );
    }

    /**
     * Build a contract-valid event of the requested type, revision and classification.
     *
     * @param   string            $eventType      Declared or undeclared event type.
     * @param   int               $schemaVersion  Registered schema revision.
     * @param   EventSensitivity  $sensitivity    Event classification, at or above the schema minimum.
     *
     * @return  RecordedIntegrationEvent  Event the contract registry accepts.
     *
     * @since   2.0.0
     */
    private static function claimedEvent(
        string $eventType,
        int $schemaVersion,
        EventSensitivity $sensitivity,
    ): RecordedIntegrationEvent {
        return new RecordedIntegrationEvent(
            new DeterministicCanonicalEncoder(),
            $eventType,
            $schemaVersion,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            null,
            'worker',
            'default',
            null,
            'acme.record',
            'record-9',
            1,
            'correlation-9',
            'request-9',
            $sensitivity,
            ['record_id' => 'record-9'],
        );
    }

    /**
     * Build a dispatcher whose contract registry knows every event the claimed-lease cases carry.
     *
     * @param   InboxStore  $inbox  Receipt ledger whose settlement calls the case observes.
     *
     * @return  DurableOutboundAdapterDispatcher  Dispatcher with a deterministic zero-delay retry policy.
     *
     * @since   2.0.0
     */
    private static function claimedDispatcher(InboxStore $inbox): DurableOutboundAdapterDispatcher
    {
        $schema = static fn (string $type, int $version): EventSchemaDefinition => new EventSchemaDefinition(
            new DeterministicCanonicalEncoder(),
            $type,
            $version,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        );

        return new DurableOutboundAdapterDispatcher(
            $inbox,
            new EventContractRegistry(new DeterministicCanonicalEncoder(), [
                $schema('acme.record.changed', 1),
                $schema('acme.record.changed', 2),
                $schema('acme.record.deleted', 1),
            ], []),
            new RetryPolicy(new OutboundDispatcherClock(), new OutboundDispatcherJitter()),
            new class implements TrustedRuntimeGenerationGuard {
                /**
                 * Accept every generation; trust revocation is not under test here.
                 *
                 * @param   string  $generation  Generation named by the lease.
                 *
                 * @return  void
                 *
                 * @since   2.0.0
                 */
                public function assertCurrent(string $generation): void
                {
                    unset($generation);
                }
            },
            new NullLogger(),
        );
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
