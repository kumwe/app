<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessIntegration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Automation\FailureClassification;
use Kumwe\App\Application\Automation\QueueRuntimePolicy;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\InboxDisposition;
use Kumwe\App\BusinessIntegration\Domain\ConsumerIdempotency;
use Kumwe\App\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\App\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\BusinessIntegration\Domain\ProcessInstance;
use Kumwe\App\BusinessIntegration\Domain\ProcessStatus;
use Kumwe\App\BusinessIntegration\Domain\ProcessWorkItem;
use Kumwe\App\BusinessIntegration\Domain\ProcessWorkKind;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineProcessManagerStore;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(BusinessIntegrationSdkMigration::class)]
#[CoversClass(DoctrineOutboxStore::class)]
#[CoversClass(DoctrineInboxStore::class)]
#[CoversClass(DoctrineProcessManagerStore::class)]
final class BusinessIntegrationPersistenceTest extends TestCase
{
    private Connection $database;

    private TableNames $tables;

    private DoctrineTransactionManager $transactions;

    private FixedBusinessIntegrationClock $clock;

    private EventContractRegistry $contracts;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->tables = new TableNames($this->database, 'kumwe_');
        $this->transactions = new DoctrineTransactionManager($this->database);
        $this->clock = new FixedBusinessIntegrationClock(new DateTimeImmutable('2026-08-10T10:00:00+00:00'));
        $schema = new EventSchemaDefinition(
            'business.record.changed',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        );
        $consumer = new EventConsumerDefinition(
            'acme.search-index',
            'business.record.changed',
            [1],
            '1.0.0',
            'integration.default',
            true,
            ConsumerIdempotency::AGGREGATE_VERSION,
        );
        $this->contracts = new EventContractRegistry([$schema], [$consumer]);
        (new CoreSchemaMigration($this->tables))->up($this->database);
        $migration = new BusinessIntegrationSdkMigration($this->tables);
        $migration->up($this->database);
        $migration->up($this->database);
    }

    public function testOutboxInsertSharesTheAuthoritativeTransactionAndUsesFencedReplayableClaims(): void
    {
        $outbox = new DoctrineOutboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $this->contracts,
        );
        $rolledBack = $this->event(1);
        try {
            $this->transactions->transactional(function () use ($outbox, $rolledBack): void {
                $outbox->append($rolledBack);
                throw new RuntimeException('authoritative mutation failed');
            });
        } catch (RuntimeException) {
        }
        self::assertSame(0, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE event_id = ?',
            $this->tables->quoted('integration_outbox'),
        ), [$rolledBack->eventId()]));
        self::assertSame(0, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE event_id = ?',
            $this->tables->quoted('business_projection_source_events'),
        ), [$rolledBack->eventId()]));

        $event = $this->event(1);
        $this->transactions->transactional(static function () use ($outbox, $event): void {
            $outbox->append($event, 3);
        });
        $lease = $outbox->claim('integration-worker-1', '7', 60);
        self::assertNotNull($lease);
        self::assertSame($event->eventId(), $lease->event->eventId());
        $outbox->complete($lease);
        $outbox->replay($event->eventId(), 'operator-1');
        $replayed = $outbox->claim('integration-worker-2', '7', 60);
        self::assertNotNull($replayed);
        self::assertSame($event->eventId(), $replayed->event->eventId());
        self::assertNotSame($lease->leaseToken, $replayed->leaseToken);
    }

    public function testOutboxBackpressureDeferralDoesNotConsumeTheAttemptBudget(): void
    {
        $outbox = new DoctrineOutboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $this->contracts,
        );
        $event = $this->event(1);
        $outbox->append($event, 1);
        $first = $outbox->claim('integration-worker-1', '7', 60);
        self::assertNotNull($first);
        self::assertSame(1, $first->attempts);
        $outbox->defer($first, 5);
        self::assertSame(0, (int) $this->database->fetchOne(sprintf(
            'SELECT attempts FROM %s WHERE event_id = ?',
            $this->tables->quoted('integration_outbox'),
        ), [$event->eventId()]));
        self::assertNull($outbox->claim('integration-worker-2', '7', 60));

        $later = new DoctrineOutboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            new FixedBusinessIntegrationClock($this->clock->now()->modify('+5 seconds')),
            $this->contracts,
        );
        $second = $later->claim('integration-worker-2', '7', 60);
        self::assertNotNull($second);
        self::assertSame(1, $second->attempts);
        $later->defer($second, 5);
        self::assertSame(0, (int) $this->database->fetchOne(sprintf(
            'SELECT attempts FROM %s WHERE event_id = ?',
            $this->tables->quoted('integration_outbox'),
        ), [$event->eventId()]));
    }

    public function testInboxDefersVersionTwoThenProcessesOneAndTwoAndDeduplicatesReplay(): void
    {
        $inbox = new DoctrineInboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $this->contracts,
        );
        $consumer = $this->contracts->consumer('acme.search-index');
        $second = $this->event(2);
        self::assertSame(
            InboxDisposition::REORDERED,
            $inbox->receive($consumer, $second, 'consumer-worker-1', '7', 60)->disposition,
        );

        $first = $this->event(1);
        $firstClaim = $inbox->receive($consumer, $first, 'consumer-worker-1', '7', 60);
        self::assertSame(InboxDisposition::CLAIMED, $firstClaim->disposition);
        self::assertNotNull($firstClaim->lease);
        $inbox->complete($firstClaim->lease);

        $secondClaim = $inbox->receive($consumer, $second, 'consumer-worker-1', '7', 60);
        self::assertSame(InboxDisposition::CLAIMED, $secondClaim->disposition);
        self::assertNotNull($secondClaim->lease);
        $inbox->complete($secondClaim->lease);
        self::assertSame(
            InboxDisposition::DUPLICATE,
            $inbox->receive($consumer, $second, 'consumer-worker-2', '7', 60)->disposition,
        );
    }

    public function testUnavailableRevisionBecomesClaimableAfterConsumerUpgrade(): void
    {
        $schemaOne = $this->contracts->schema('business.record.changed', 1);
        $schemaTwo = new EventSchemaDefinition(
            'business.record.changed',
            2,
            EventSensitivity::INTERNAL,
            $schemaOne->payloadSchema(),
        );
        $original = new EventConsumerDefinition(
            'acme.upgradeable-index',
            'business.record.changed',
            [1],
            '1.0.0',
        );
        $originalRegistry = new EventContractRegistry([$schemaOne, $schemaTwo], [$original]);
        $inbox = new DoctrineInboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $originalRegistry,
        );
        $event = $this->event(1, 2);

        self::assertSame(
            InboxDisposition::UNAVAILABLE,
            $inbox->receive($original, $event, 'consumer-worker-1', '7', 60)->disposition,
        );

        $upgraded = new EventConsumerDefinition(
            'acme.upgradeable-index',
            'business.record.changed',
            [1, 2],
            '2.0.0',
        );
        $upgradedRegistry = new EventContractRegistry([$schemaOne, $schemaTwo], [$upgraded]);
        $upgradedInbox = new DoctrineInboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $upgradedRegistry,
        );
        $claim = $upgradedInbox->receive($upgraded, $event, 'consumer-worker-2', '8', 60);

        self::assertSame(InboxDisposition::CLAIMED, $claim->disposition);
        self::assertNotNull($claim->lease);
        self::assertSame('2.0.0', $claim->lease->consumer->handlerVersion());
    }

    public function testPermanentConsumerFailureBecomesTerminalPoison(): void
    {
        $inbox = new DoctrineInboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $this->contracts,
        );
        $consumer = $this->contracts->consumer('acme.search-index');
        $event = $this->event(1);
        $claim = $inbox->receive($consumer, $event, 'consumer-worker-1', '7', 60);
        self::assertNotNull($claim->lease);

        $inbox->fail(
            $claim->lease,
            FailureClassification::PERMANENT,
            new RuntimeException('permanent handler rejection'),
            null,
        );

        self::assertSame(
            InboxDisposition::POISON,
            $inbox->receive($consumer, $event, 'consumer-worker-2', '7', 60)->disposition,
        );

        $upgraded = new EventConsumerDefinition(
            $consumer->identifier(),
            $consumer->eventType(),
            $consumer->schemaVersions(),
            '2.0.0',
            $consumer->queue(),
            $consumer->aggregateOrdered(),
            $consumer->idempotency(),
            $consumer->maximumAttempts(),
            $consumer->sensitivityCeiling(),
        );
        $upgradedRegistry = new EventContractRegistry(
            [$this->contracts->schema('business.record.changed', 1)],
            [$upgraded],
        );
        $upgradedInbox = new DoctrineInboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $upgradedRegistry,
        );
        $recovered = $upgradedInbox->receive($upgraded, $event, 'consumer-worker-3', '8', 60);

        self::assertSame(InboxDisposition::CLAIMED, $recovered->disposition);
        self::assertNotNull($recovered->lease);
        self::assertSame(1, $recovered->lease->attempts);
    }

    public function testTransientConsumerFailureCannotBeReclaimedBeforeItsRetryTime(): void
    {
        $inbox = new DoctrineInboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $this->contracts,
        );
        $consumer = $this->contracts->consumer('acme.search-index');
        $event = $this->event(1);
        $claim = $inbox->receive($consumer, $event, 'consumer-worker-1', '7', 60);
        self::assertNotNull($claim->lease);
        $retryAt = $this->clock->now()->modify('+5 minutes');

        $inbox->fail(
            $claim->lease,
            FailureClassification::TRANSIENT,
            new RuntimeException('temporary handler failure'),
            $retryAt,
        );

        $early = $inbox->receive($consumer, $event, 'consumer-worker-2', '7', 60);
        self::assertSame(InboxDisposition::BUSY, $early->disposition);
        self::assertNull($early->lease);

        $afterBackoff = new DoctrineInboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            new FixedBusinessIntegrationClock($retryAt),
            $this->contracts,
        );
        $retry = $afterBackoff->receive($consumer, $event, 'consumer-worker-2', '7', 60);

        self::assertSame(InboxDisposition::CLAIMED, $retry->disposition);
        self::assertNotNull($retry->lease);
        self::assertSame(2, $retry->lease->attempts);
    }

    public function testQueuePolicyCapsInboxLeaseAttemptsAndSharedJobDeliveryCapacity(): void
    {
        $consumer = new EventConsumerDefinition(
            'acme.queue-consumer',
            'business.record.changed',
            [1],
            '1.0.0',
            'integration.default',
            false,
            ConsumerIdempotency::EVENT_ID,
            8,
        );
        $contracts = new EventContractRegistry(
            [$this->contracts->schema('business.record.changed', 1)],
            [$consumer],
        );
        $policy = new PersistenceQueuePolicyCatalog(new QueueRuntimePolicy(
            'integration.default',
            30,
            3,
            1,
            30,
            7,
        ));
        $inbox = new DoctrineInboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $contracts,
            $policy,
        );
        try {
            $inbox->receive($consumer, $this->event(1), 'consumer-worker-1', '7', 31);
            self::fail('A delivery lease exceeded its signed queue policy.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('signed policy', $exception->getMessage());
        }

        $now = $this->clock->now();
        $jobId = Uuid::uuid7()->toString();
        $this->database->insert($this->tables->raw('jobs'), [
            'id' => $jobId,
            'queue' => 'integration.default',
            'job_type' => 'acme.same-name-is-not-consulted',
            'schema_version' => 1,
            'payload' => [],
            'priority' => 0,
            'status' => 'reserved',
            'available_at' => $now,
            'lease_owner' => 'job-worker',
            'lease_acquired_at' => $now,
            'lease_expires_at' => $now->modify('+10 minutes'),
            'attempts' => 1,
            'maximum_attempts' => 10,
            'schedule_id' => null,
            'scheduled_for' => null,
            'occurrence_key' => null,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'id' => Types::GUID,
            'payload' => Types::JSON,
            'available_at' => Types::DATETIME_IMMUTABLE,
            'lease_acquired_at' => Types::DATETIME_IMMUTABLE,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $first = $this->event(1);
        self::assertSame(
            InboxDisposition::BUSY,
            $inbox->receive($consumer, $first, 'consumer-worker-1', '7', 30)->disposition,
        );

        $this->database->delete($this->tables->raw('jobs'), ['id' => $jobId], ['id' => Types::GUID]);
        $firstClaim = $inbox->receive($consumer, $first, 'consumer-worker-1', '7', 30);
        self::assertSame(InboxDisposition::CLAIMED, $firstClaim->disposition);
        self::assertNotNull($firstClaim->lease);
        self::assertSame(3, $firstClaim->lease->consumer->maximumAttempts());
        self::assertSame(3, (int) $this->database->fetchOne(sprintf(
            'SELECT maximum_attempts FROM %s WHERE consumer_id = ? AND event_id = ?',
            $this->tables->quoted('integration_inbox'),
        ), [$consumer->identifier(), $first->eventId()]));

        $second = $this->event(2);
        self::assertSame(
            InboxDisposition::BUSY,
            $inbox->receive($consumer, $second, 'consumer-worker-2', '7', 30)->disposition,
        );
        $inbox->complete($firstClaim->lease);
        $secondClaim = $inbox->receive($consumer, $second, 'consumer-worker-2', '7', 30);
        self::assertSame(InboxDisposition::CLAIMED, $secondClaim->disposition);
        self::assertNotNull($secondClaim->lease);

        $this->database->update($this->tables->raw('integration_inbox'), [
            'lease_expires_at' => null,
        ], [
            'consumer_id' => $consumer->identifier(),
            'event_id' => $second->eventId(),
        ], ['event_id' => Types::GUID]);
        try {
            $inbox->receive($consumer, $second, 'consumer-worker-3', '7', 30);
            self::fail('A malformed reserved inbox lease was reclaimed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('invalid lease expiration', $exception->getMessage());
        }
    }

    public function testAggregateCheckpointsAreIsolatedBySiteAndOrganizationScope(): void
    {
        $inbox = new DoctrineInboxStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
            $this->contracts,
        );
        $consumer = $this->contracts->consumer('acme.search-index');
        $events = [
            $this->event(1, siteIdentifier: 'site-a', organizationId: 'organization-a'),
            $this->event(1, siteIdentifier: 'site-b', organizationId: 'organization-a'),
            $this->event(1, siteIdentifier: 'site-a', organizationId: 'organization-b'),
            $this->event(1, siteIdentifier: 'site-a', organizationId: null),
        ];

        foreach ($events as $offset => $event) {
            $claim = $inbox->receive($consumer, $event, 'consumer-worker-' . $offset, '7', 60);
            self::assertSame(InboxDisposition::CLAIMED, $claim->disposition);
            self::assertNotNull($claim->lease);
            $inbox->complete($claim->lease);
        }

        self::assertSame(4, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE consumer_id = ?',
            $this->tables->quoted('integration_consumer_checkpoints'),
        ), [$consumer->identifier()]));
        self::assertSame(1, (int) $this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE site_identifier = 'site-a' AND organization_scope = ''",
            $this->tables->quoted('integration_consumer_checkpoints'),
        )));
    }

    public function testProcessStateAndWorkPersistAtomicallyWithOptimisticFencing(): void
    {
        $store = new DoctrineProcessManagerStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
        );
        $process = new ProcessInstance(
            Uuid::uuid7()->toString(),
            'purchase.fulfilment',
            'order-77',
            'default',
            'organization-1',
            'actor-1',
            null,
            1,
            ProcessStatus::RUNNING,
            ['step' => 1],
            $this->clock->now(),
            $this->clock->now(),
        );
        $work = new ProcessWorkItem(
            Uuid::uuid7()->toString(),
            ProcessWorkKind::COMMAND,
            'inventory.reserve',
            ['sku' => 'SKU-1'],
            $this->clock->now(),
        );
        $store->create($process, [$work]);

        $otherSite = new ProcessInstance(
            Uuid::uuid7()->toString(),
            $process->processType(),
            $process->correlationId(),
            'secondary',
            'organization-2',
            'actor-2',
            null,
            1,
            ProcessStatus::RUNNING,
            ['step' => 1],
            $this->clock->now(),
            $this->clock->now(),
        );
        $store->create($otherSite);
        self::assertSame(
            $otherSite->id(),
            $store->findByCorrelation($process->processType(), 'secondary', $process->correlationId())?->id(),
        );
        self::assertSame(
            $process->id(),
            $store->findByCorrelation($process->processType(), 'default', $process->correlationId())?->id(),
        );

        $lease = $store->claimWork('process-worker-1', '7', 60);
        self::assertNotNull($lease);
        self::assertSame($process->id(), $lease->processId);
        self::assertSame('default', $lease->siteIdentifier);
        self::assertSame('organization-1', $lease->organizationId);
        $store->completeWork($lease);

        $next = $process->transition(['step' => 2], ProcessStatus::RUNNING, $this->clock->now());
        $store->save($next, 1);
        self::assertSame(2, $store->load($process->id())?->version());

        $this->expectException(RuntimeException::class);
        $store->save($next, 1);
    }

    private function event(
        int $aggregateVersion,
        int $schemaVersion = 1,
        string $siteIdentifier = 'default',
        ?string $organizationId = 'organization-1',
    ): IntegrationEvent {
        return new IntegrationEvent(
            'business.record.changed',
            $schemaVersion,
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            'actor-1',
            null,
            $siteIdentifier,
            $organizationId,
            'business.record',
            'record-7',
            $aggregateVersion,
            'correlation-1',
            'request-' . $aggregateVersion,
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-7'],
        );
    }
}

final readonly class FixedBusinessIntegrationClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $instant)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}

final readonly class PersistenceQueuePolicyCatalog implements QueueRuntimePolicyCatalog
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
        // Deliberately narrower than the queue policy to model a same-named contributed job.
        // Inbox enforcement must not consult this job-aware method for a consumer identifier.
        return $this->policy($queue) === null ? $requested : 1;
    }

    public function policies(): array
    {
        return [$this->policy];
    }
}
