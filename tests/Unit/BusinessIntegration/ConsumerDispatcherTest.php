<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\Automation\FailureClassification;
use Kumwe\Automation\JitterSource;
use Kumwe\Automation\QueueRuntimePolicy;
use Kumwe\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Automation\RetryPolicy;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\InboxClaimResult;
use Kumwe\Integration\InboxDisposition;
use Kumwe\Integration\InboxLease;
use Kumwe\Integration\InboxStore;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\Extension\Spi\Application\ExecutionContext as ExtensionExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\EventSchemaDefinition;
use Kumwe\Integration\EventSensitivity;
use Kumwe\Integration\IntegrationEvent;
use Kumwe\Integration\RecordedIntegrationEvent;
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

#[CoversClass(IntegrationEventConsumerDispatcher::class)]
final class ConsumerDispatcherTest extends TestCase
{
    public function testContributedQueueLeaseIsTheDeliveryDefaultAndCannotBeWidened(): void
    {
        $clock = new DispatcherTestClock();
        $definition = new EventConsumerDefinition(
            'acme.policy-consumer',
            'business.record.changed',
            [1],
            '1.0.0',
            'integration.default',
            false,
        );
        $event = new RecordedIntegrationEvent(
            new DeterministicCanonicalEncoder(),
            'business.record.changed',
            1,
            Uuid::uuid7()->toString(),
            $clock->now(),
            null,
            'worker',
            'default',
            null,
            'business.record',
            'record-8',
            1,
            'correlation-2',
            'request-2',
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-8'],
        );
        $registry = new EventContractRegistry(new DeterministicCanonicalEncoder(), [new EventSchemaDefinition(
            new DeterministicCanonicalEncoder(),
            'business.record.changed',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        )], [$definition]);
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::once())->method('receive')->with(
            $definition,
            $event,
            'consumer-worker-1',
            '7',
            30,
        )->willReturn(new InboxClaimResult(InboxDisposition::DUPLICATE));
        $transactions = $this->createStub(TransactionManager::class);
        $dispatcher = new IntegrationEventConsumerDispatcher(
            $inbox,
            $registry,
            new RetryPolicy($clock, new ZeroJitter()),
            new AlwaysCurrentRuntime(),
            $transactions,
            new NullLogger(),
            new ConsumerDispatcherQueueCatalog(new QueueRuntimePolicy(
                'integration.default',
                30,
                3,
                2,
                14,
                7,
            )),
        );
        $handler = new SuccessfulIntegrationHandler();
        $context = ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            'consumer-test',
        );

        self::assertSame(InboxDisposition::DUPLICATE, $dispatcher->consume(
            $definition,
            $event,
            $handler,
            $context,
            'consumer-worker-1',
            '7',
        ));
        try {
            $dispatcher->consume($definition, $event, $handler, $context, 'consumer-worker-1', '7', 31);
            self::fail('An explicit consumer lease exceeded its signed queue policy.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('signed policy', $exception->getMessage());
        }
    }

    public function testHandlerFailureIsRecordedAndRethrownForTransportRedelivery(): void
    {
        $clock = new DispatcherTestClock();
        $definition = new EventConsumerDefinition(
            'acme.search-index',
            'business.record.changed',
            [1],
            '1.0.0',
        );
        $event = new RecordedIntegrationEvent(
            new DeterministicCanonicalEncoder(),
            'business.record.changed',
            1,
            Uuid::uuid7()->toString(),
            $clock->now(),
            null,
            'worker',
            'default',
            null,
            'business.record',
            'record-7',
            1,
            'correlation-1',
            'request-1',
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-7'],
        );
        $registry = new EventContractRegistry(new DeterministicCanonicalEncoder(), [
            new EventSchemaDefinition(
                new DeterministicCanonicalEncoder(),
                'business.record.changed',
                1,
                EventSensitivity::INTERNAL,
                [
                    'type' => 'object',
                    'required' => ['record_id'],
                    'properties' => ['record_id' => ['type' => 'string']],
                    'additionalProperties' => false,
                ],
            ),
        ], [$definition]);
        $inbox = new RecordingInboxStore($definition, $event);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $dispatcher = new IntegrationEventConsumerDispatcher(
            $inbox,
            $registry,
            new RetryPolicy($clock, new ZeroJitter()),
            new AlwaysCurrentRuntime(),
            $transactions,
            new NullLogger(),
        );
        $handler = new FailingIntegrationHandler();
        $context = ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            'consumer-test',
        );

        try {
            $dispatcher->consume($definition, $event, $handler, $context, 'consumer-worker-1', '7');
            self::fail('A failed durable consumer must force transport redelivery.');
        } catch (RuntimeException $exception) {
            self::assertSame('handler unavailable', $exception->getMessage());
        }
        self::assertTrue($inbox->failed);
    }

    /**
     * Prove a claimed receipt that disagrees with its signed consumer or its site is refused before any effect.
     *
     * Each case changes one thing the host must not trust from the durable lease: a consumer contract field
     * other than the attempt budget, an attempt budget wider than the signed one, or an execution context
     * issued for another site. The handler never runs and no transaction or receipt settlement starts.
     *
     * @param   string  $handlerVersion   Handler revision recorded on the lease.
     * @param   int     $maximumAttempts  Attempt budget recorded on the lease.
     * @param   string  $contextSite      Site the worker's execution context was issued for.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('mismatchedReceipts')]
    public function testAClaimedReceiptThatDisagreesWithItsConsumerOrSiteIsRefusedBeforeAnyEffect(
        string $handlerVersion,
        int $maximumAttempts,
        string $contextSite,
    ): void {
        $registered = self::claimedConsumer('1.0.0', 5);
        $event = self::claimedEvent();
        $lease = new InboxLease(
            self::claimedConsumer($handlerVersion, $maximumAttempts),
            $event,
            1,
            'consumer-worker-1',
            Uuid::uuid7()->toString(),
            '7',
        );
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::never())->method('complete');
        $inbox->expects(self::never())->method('fail');
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::never())->method('transactional');
        $handler = $this->createMock(IntegrationEventHandler::class);
        $handler->expects(self::never())->method('handle');
        $dispatcher = new IntegrationEventConsumerDispatcher(
            $inbox,
            self::claimedRegistry($registered),
            new RetryPolicy(new DispatcherTestClock(), new ZeroJitter()),
            new AlwaysCurrentRuntime(),
            $transactions,
            new NullLogger(),
        );
        $context = ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::fromString($contextSite),
            'consumer-test',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A claimed receipt does not match its trusted consumer or site.');

        $dispatcher->consumeClaimed($lease, $handler, $context);
    }

    /**
     * Name each divergence between a claimed receipt and its signed consumer or site authority.
     *
     * @return  array<string, array{string, int, string}>  Lease handler revision, lease attempt budget and
     *          execution-context site, with exactly one out of line with the signed consumer (1.0.0, 5, default).
     *
     * @since   2.0.0
     */
    public static function mismatchedReceipts(): array
    {
        return [
            'another signed handler revision' => ['2.0.0', 5, 'default'],
            'an attempt budget wider than signed' => ['1.0.0', 6, 'default'],
            'an execution context for another site' => ['1.0.0', 5, 'other-site'],
        ];
    }

    /**
     * Prove a queue-narrowed attempt budget is not mistaken for a contract mismatch.
     *
     * The inbox records the effective budget after the queue policy narrows it, so the lease may carry
     * fewer attempts than the signed consumer. That receipt executes and settles normally.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAClaimedReceiptWithAQueueNarrowedAttemptBudgetExecutesAndSettles(): void
    {
        $registered = self::claimedConsumer('1.0.0', 5);
        $lease = new InboxLease(
            self::claimedConsumer('1.0.0', 3),
            self::claimedEvent(),
            1,
            'consumer-worker-1',
            Uuid::uuid7()->toString(),
            '7',
        );
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::once())->method('complete')->with($lease);
        $inbox->expects(self::never())->method('fail');
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $handler = $this->createMock(IntegrationEventHandler::class);
        $handler->expects(self::once())->method('handle')->with($registered, $lease->event);
        $dispatcher = new IntegrationEventConsumerDispatcher(
            $inbox,
            self::claimedRegistry($registered),
            new RetryPolicy(new DispatcherTestClock(), new ZeroJitter()),
            new AlwaysCurrentRuntime(),
            $transactions,
            new NullLogger(),
        );
        $context = ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            'consumer-test',
        );

        self::assertSame(InboxDisposition::CLAIMED, $dispatcher->consumeClaimed($lease, $handler, $context));
    }

    /**
     * Build the consumer contract a claimed receipt is compared against.
     *
     * @param   string  $handlerVersion   Signed handler revision.
     * @param   int     $maximumAttempts  Attempt budget.
     *
     * @return  EventConsumerDefinition  Unordered consumer of the record-changed event.
     *
     * @since   2.0.0
     */
    private static function claimedConsumer(string $handlerVersion, int $maximumAttempts): EventConsumerDefinition
    {
        return new EventConsumerDefinition(
            'acme.claimed-index',
            'business.record.changed',
            [1],
            $handlerVersion,
            'integration.default',
            false,
            maximumAttempts: $maximumAttempts,
        );
    }

    /**
     * Build a contract-valid event owned by the default site.
     *
     * @return  RecordedIntegrationEvent  Event the claimed receipt carries.
     *
     * @since   2.0.0
     */
    private static function claimedEvent(): RecordedIntegrationEvent
    {
        return new RecordedIntegrationEvent(
            new DeterministicCanonicalEncoder(),
            'business.record.changed',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            null,
            'worker',
            'default',
            null,
            'business.record',
            'record-9',
            1,
            'correlation-9',
            'request-9',
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-9'],
        );
    }

    /**
     * Build the trusted contract registry that signs exactly one consumer.
     *
     * @param   EventConsumerDefinition  $registered  Signed consumer declaration.
     *
     * @return  EventContractRegistry  Registry with the record-changed schema.
     *
     * @since   2.0.0
     */
    private static function claimedRegistry(EventConsumerDefinition $registered): EventContractRegistry
    {
        return new EventContractRegistry(new DeterministicCanonicalEncoder(), [new EventSchemaDefinition(
            new DeterministicCanonicalEncoder(),
            'business.record.changed',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        )], [$registered]);
    }
}

final class RecordingInboxStore implements InboxStore
{
    public bool $failed = false;

    private InboxLease $lease;

    public function __construct(EventConsumerDefinition $consumer, IntegrationEvent $event)
    {
        $this->lease = new InboxLease(
            $consumer,
            $event,
            1,
            'consumer-worker-1',
            Uuid::uuid7()->toString(),
            '7',
        );
    }

    public function receive(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds,
    ): InboxClaimResult {
        return new InboxClaimResult(InboxDisposition::CLAIMED, $this->lease);
    }

    public function renew(InboxLease $lease, int $leaseSeconds): void
    {
    }

    public function complete(InboxLease $lease): void
    {
    }

    public function fail(
        InboxLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void {
        $this->failed = true;
    }

    public function recent(string $consumerId, int $limit = 100): array
    {
        return [];
    }
}

final readonly class FailingIntegrationHandler implements IntegrationEventHandler
{
    public function handle(
        EventConsumerDefinition $definition,
        IntegrationEvent $event,
        ExtensionExecutionContext $context,
    ): void {
        throw new RuntimeException('handler unavailable');
    }
}

final readonly class SuccessfulIntegrationHandler implements IntegrationEventHandler
{
    public function handle(
        EventConsumerDefinition $definition,
        IntegrationEvent $event,
        ExtensionExecutionContext $context,
    ): void {
    }
}

final readonly class AlwaysCurrentRuntime implements TrustedRuntimeGenerationGuard
{
    public function assertCurrent(string $generation): void
    {
    }
}

final readonly class DispatcherTestClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-10T10:00:00+00:00');
    }
}

final readonly class ZeroJitter implements JitterSource
{
    public function between(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}

final readonly class ConsumerDispatcherQueueCatalog implements QueueRuntimePolicyCatalog
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
