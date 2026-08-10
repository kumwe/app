<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessIntegration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\CMS\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\CMS\BusinessIntegration\Application\InboxDisposition;
use Kumwe\CMS\BusinessIntegration\Domain\ConsumerIdempotency;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessInstance;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessStatus;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessWorkItem;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessWorkKind;
use Kumwe\CMS\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\CMS\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\CMS\BusinessIntegration\Infrastructure\DoctrineProcessManagerStore;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
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
        $this->tables = new TableNames($this->database, '');
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
        $lease = $store->claimWork('process-worker-1', '7', 60);
        self::assertNotNull($lease);
        self::assertSame($process->id(), $lease->processId);
        $store->completeWork($lease);

        $next = $process->transition(['step' => 2], ProcessStatus::RUNNING, $this->clock->now());
        $store->save($next, 1);
        self::assertSame(2, $store->load($process->id())?->version());

        $this->expectException(RuntimeException::class);
        $store->save($next, 1);
    }

    private function event(int $aggregateVersion): IntegrationEvent
    {
        return new IntegrationEvent(
            'business.record.changed',
            1,
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            'actor-1',
            null,
            'default',
            'organization-1',
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
