<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessIntegration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Kernel\Container;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\InboxDisposition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\ConsumerIdempotency;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves outbox and inbox claim arbitration on the configured engine, across two real connections.
 *
 * The store-level suite runs on SQLite in memory, where `lockClause()` compiles to an empty string, so
 * every assertion it makes about `FOR UPDATE SKIP LOCKED` is vacuously true there. These tests take the
 * configured database instead and force the interleaving the clause exists for: one session holds a row
 * and the other must observably route around it — or, for the inbox, observably wait for it.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineOutboxStore::class)]
#[CoversClass(DoctrineInboxStore::class)]
final class OutboxInboxClaimContentionIntegrationTest extends TestCase
{
    private const CONSUMER = 'acme.contention-index';

    private const EVENT_TYPE = 'business.record.changed';

    public function testASecondDispatcherSkipsTheEventTheFirstIsHoldingInsteadOfBlockingOnIt(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $database = $this->connection($primary);
        $this->skipWithoutRowLocks($database, 'skip-locked claim arbitration');

        $secondary = TestKernelFactory::create($environment);
        $concurrent = $this->connection($secondary);
        $clock = new MovableContentionClock(new DateTimeImmutable('2026-08-14T10:00:00', new DateTimeZone('UTC')));
        $held = $this->outbox($primary, $clock);
        $rival = $this->outbox($secondary, $clock);
        $this->clearOutbox($database);
        $this->boundLockWait($concurrent);

        $first = $this->event(1);
        $second = $this->event(2);
        $this->transactions($primary)->transactional(static function () use ($held, $first, $second): void {
            $held->append($first);
            $held->append($second);
        });

        try {
            $database->beginTransaction();
            $locked = $database->fetchOne(sprintf(
                'SELECT event_id FROM %s WHERE event_id = ? FOR UPDATE',
                $this->tables($primary)->quoted('integration_outbox'),
            ), [$first->eventId()], [Types::GUID]);
            self::assertSame($first->eventId(), $locked);

            $lease = $rival->claim('contention-worker-b', '9', 60);
            self::assertNotNull($lease, 'A held event must not stall the whole dispatcher.');
            self::assertSame(
                $second->eventId(),
                $lease->event->eventId(),
                'The rival dispatcher skipped the locked event instead of waiting on it.',
            );
            self::assertSame('pending', $database->fetchOne(sprintf(
                'SELECT status FROM %s WHERE event_id = ?',
                $this->tables($primary)->quoted('integration_outbox'),
            ), [$first->eventId()], [Types::GUID]));
            $database->rollBack();

            $released = $rival->claim('contention-worker-b', '9', 60);
            self::assertNotNull($released);
            self::assertSame(
                $first->eventId(),
                $released->event->eventId(),
                'Once released, the skipped event is claimable by the same dispatcher.',
            );
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $concurrent->close();
        }
    }

    public function testAnExpiredOutboxLeaseIsReclaimedAndTheOldHolderIsRefusedItsSettlement(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $secondary = TestKernelFactory::create($environment);
        $database = $this->connection($primary);
        $clock = new MovableContentionClock(new DateTimeImmutable('2026-08-14T10:00:00', new DateTimeZone('UTC')));
        $original = $this->outbox($primary, $clock);
        $successor = $this->outbox($secondary, $clock);
        $this->clearOutbox($database);

        $event = $this->event(3);
        $this->transactions($primary)->transactional(static function () use ($original, $event): void {
            $original->append($event);
        });

        $stale = $original->claim('contention-worker-a', '9', 30);
        self::assertNotNull($stale);
        self::assertSame($event->eventId(), $stale->event->eventId());

        $clock->advance(31);
        $reclaimed = $successor->claim('contention-worker-b', '9', 30);
        self::assertNotNull($reclaimed, 'An expired lease must be reclaimable by another connection.');
        self::assertSame($event->eventId(), $reclaimed->event->eventId());
        self::assertNotSame($stale->leaseToken, $reclaimed->leaseToken);
        self::assertSame(2, $reclaimed->attempts);

        try {
            $original->complete($stale);
            self::fail('A superseded holder cannot settle an event it no longer owns.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('no longer owns', $exception->getMessage());
        }
        $successor->complete($reclaimed);
        self::assertSame('dispatched', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE event_id = ?',
            $this->tables($primary)->quoted('integration_outbox'),
        ), [$event->eventId()], [Types::GUID]));
    }

    public function testASecondConsumerWaitsForTheReceiptRowTheFirstIsHoldingRatherThanClaimingBeside(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $database = $this->connection($primary);
        $this->skipWithoutRowLocks($database, 'receipt-row serialization');

        $secondary = TestKernelFactory::create($environment);
        $concurrent = $this->connection($secondary);
        $clock = new MovableContentionClock(new DateTimeImmutable('2026-08-14T10:00:00', new DateTimeZone('UTC')));
        $inbox = $this->inbox($primary, $clock);
        $rival = $this->inbox($secondary, $clock);
        $this->boundLockWait($concurrent);

        $event = $this->event(1, 'inbox-' . Uuid::uuid7()->toString());
        $claim = $inbox->receive($this->consumer(), $event, 'contention-consumer-a', '9', 60);
        self::assertSame(InboxDisposition::CLAIMED, $claim->disposition);
        self::assertNotNull($claim->lease);
        $inbox->complete($claim->lease);

        try {
            $database->beginTransaction();
            self::assertSame($event->eventId(), $database->fetchOne(sprintf(
                'SELECT event_id FROM %s WHERE consumer_id = ? AND event_id = ? FOR UPDATE',
                $this->tables($primary)->quoted('integration_inbox'),
            ), [self::CONSUMER, $event->eventId()], [Types::STRING, Types::GUID]));

            try {
                $rival->receive($this->consumer(), $event, 'contention-consumer-b', '9', 60);
                self::fail('A second consumer cannot read past a receipt row another session is holding.');
            } catch (DbalException) {
                self::assertSame(
                    'completed',
                    $database->fetchOne(sprintf(
                        'SELECT status FROM %s WHERE consumer_id = ? AND event_id = ?',
                        $this->tables($primary)->quoted('integration_inbox'),
                    ), [self::CONSUMER, $event->eventId()], [Types::STRING, Types::GUID]),
                    'The blocked consumer wrote nothing; the receipt is still the completed one.',
                );
            }
            $database->rollBack();

            $duplicate = $rival->receive($this->consumer(), $event, 'contention-consumer-b', '9', 60);
            self::assertSame(
                InboxDisposition::DUPLICATE,
                $duplicate->disposition,
                'Once the row is released, the second consumer sees the completed receipt and stands down.',
            );
            self::assertNull($duplicate->lease);
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $concurrent->close();
        }
    }

    /**
     * Refuse to run a lock-arbitration assertion on an engine whose lock clause compiles to nothing.
     */
    private function skipWithoutRowLocks(Connection $database, string $property): void
    {
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(sprintf(
                'SQLite compiles the store lock clause to an empty string, so %s cannot be observed there '
                . 'and asserting it would pass vacuously.',
                $property,
            ));
        }
    }

    /**
     * Make a blocked session fail quickly instead of waiting out the server default.
     */
    private function boundLockWait(Connection $connection): void
    {
        $connection->executeStatement(
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '500ms'",
        );
    }

    /**
     * Start each outbox scenario from an empty table so claim order is decided by this test alone.
     */
    private function clearOutbox(Connection $database): void
    {
        $database->executeStatement(sprintf(
            'DELETE FROM %s',
            (new TableNames($database, $this->prefix()))->quoted('integration_outbox'),
        ));
    }

    private function outbox(Container $container, ClockInterface $clock): DoctrineOutboxStore
    {
        return new DoctrineOutboxStore(
            $this->connection($container),
            $this->tables($container),
            $this->transactions($container),
            $clock,
            $this->contracts(),
        );
    }

    private function inbox(Container $container, ClockInterface $clock): DoctrineInboxStore
    {
        return new DoctrineInboxStore(
            $this->connection($container),
            $this->tables($container),
            $this->transactions($container),
            $clock,
            $this->contracts(),
        );
    }

    private function contracts(): EventContractRegistry
    {
        return new EventContractRegistry([
            new EventSchemaDefinition(self::EVENT_TYPE, 1, EventSensitivity::INTERNAL, [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ]),
        ], [$this->consumer()]);
    }

    private function consumer(): EventConsumerDefinition
    {
        return new EventConsumerDefinition(
            self::CONSUMER,
            self::EVENT_TYPE,
            [1],
            '1.0.0',
            'integration.default',
            true,
            ConsumerIdempotency::AGGREGATE_VERSION,
        );
    }

    private function event(int $aggregateVersion, ?string $aggregateId = null): IntegrationEvent
    {
        $aggregateId ??= 'record-contention-' . $aggregateVersion;

        return new RecordedIntegrationEvent(
            self::EVENT_TYPE,
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-14T10:00:00', new DateTimeZone('UTC')),
            'contention-actor',
            null,
            'default',
            'organization-contention',
            'business.record',
            $aggregateId,
            $aggregateVersion,
            'correlation-contention',
            'request-contention-' . $aggregateVersion,
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-contention'],
        );
    }

    private function transactions(Container $container): DoctrineTransactionManager
    {
        return new DoctrineTransactionManager($this->connection($container));
    }

    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }

    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
    }

    private function prefix(): string
    {
        return Environment::fromGlobals()->string('DB_TABLE_PREFIX', 'kumwe_');
    }
}

/**
 * A clock the test moves by hand so a lease expires exactly when the scenario says it does.
 *
 * @since  2.0.0
 */
final class MovableContentionClock implements ClockInterface
{
    /**
     * Hold the instant every reader of this clock currently sees.
     *
     * @param  DateTimeImmutable  $instant  Current instant.
     *
     * @since  2.0.0
     */
    public function __construct(private DateTimeImmutable $instant)
    {
    }

    /**
     * Report the instant this clock currently stands at.
     *
     * @return  DateTimeImmutable  Current instant.
     *
     * @since   2.0.0
     */
    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    /**
     * Move the clock forward, which is how a lease is made to expire deterministically.
     *
     * @param   int  $seconds  Seconds to advance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function advance(int $seconds): void
    {
        $this->instant = $this->instant->modify(sprintf('+%d seconds', $seconds));
    }
}
