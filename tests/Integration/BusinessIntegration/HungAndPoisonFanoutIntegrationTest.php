<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessIntegration;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\EventSchemaDefinition;
use Kumwe\Integration\EventSensitivity;
use Kumwe\Integration\InboxLease;
use Kumwe\Integration\RecordedIntegrationEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Proves, on the configured engine, that a hung and a poison consumer never delay their siblings.
 *
 * V2-SCL-006 names fan-out three and ten with one hung consumer and one poison payload. The hung
 * consumer here is exactly what a wedged handler looks like to the durable store: a claimed receipt whose
 * lease and consumer execution permit are held by a worker that never settles. The poison consumer's
 * receipt carries an envelope the contract rejects. Against both, a second worker on its own session
 * claims and settles every other consumer's receipt in one short pass, keeps doing so for new events
 * while the hung claim is still outstanding, and reclaims the hung receipt only once its lease expires.
 * The SQLite fan-out test proves the same rules in-process; this one proves them against real row locks.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineInboxStore::class)]
final class HungAndPoisonFanoutIntegrationTest extends TestCase
{
    /**
     * Wall-clock bound on a claim pass that must not wait behind the hung or poison consumer.
     *
     * @var    float
     * @since  2.0.0
     */
    private const float UNRELATED_CLAIM_BUDGET_SECONDS = 5.0;

    /**
     * Fan-out of three and ten with one hung and one poison consumer settles every unrelated receipt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHungAndPoisonConsumersDoNotDelayUnrelatedConsumersAtFanoutThreeAndTen(): void
    {
        foreach ([3, 10] as $count) {
            $nonce = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
            $type = 'acme.fanout' . $nonce . '.changed';
            $consumers = [];
            for ($index = 0; $index < $count; $index++) {
                $consumers[] = new EventConsumerDefinition(
                    sprintf('acme.fanout%s.consumer-%02d', $nonce, $index),
                    $type,
                    [1],
                    '1.0.0',
                    'integration.default',
                    false,
                );
            }
            $identifiers = array_map(
                static fn (EventConsumerDefinition $consumer): string => $consumer->identifier(),
                $consumers,
            );
            [$hungSession, $hungTables, $contracts, $clock] = $this->session($type, $consumers);
            [$fastSession, $fastTables] = $this->session($type, $consumers);
            $encoder = new DeterministicCanonicalEncoder();
            $hungStore = $this->store($hungSession, $hungTables, $clock, $contracts);
            $fastStore = $this->store($fastSession, $fastTables, $clock, $contracts);
            try {
                $first = $this->event($type, $encoder);
                $hungStore->materialize($consumers, $first);
                $hungSession->update($hungTables->raw('integration_inbox'), ['envelope' => '{}'], [
                    'consumer_id' => $identifiers[1],
                    'event_id' => $first->eventId(),
                ]);
                $hung = $hungStore->claimBatch([$consumers[0]], $encoder, 'replica-hung', '7', 30);
                self::assertCount(1, $hung, 'The hung consumer must hold a real claimed receipt.');

                $started = hrtime(true);
                $leases = $fastStore->claimBatch($consumers, $encoder, 'replica-fast', '7', 30, 64);
                $elapsed = (hrtime(true) - $started) / 1_000_000_000;
                self::assertLessThan(
                    self::UNRELATED_CLAIM_BUDGET_SECONDS,
                    $elapsed,
                    'Claiming the unrelated receipts must not wait behind the hung or poison consumer.',
                );
                self::assertSame(
                    array_slice($identifiers, 2),
                    $this->consumersOf($leases),
                    sprintf('Every consumer but the hung and poison ones is claimed at fan-out %d.', $count),
                );
                foreach ($leases as $lease) {
                    $fastStore->complete($lease);
                }
                self::assertSame(
                    'poison',
                    $this->receiptStatus($fastSession, $fastTables, $identifiers[1], $first->eventId()),
                );
                self::assertSame(
                    'reserved',
                    $this->receiptStatus($fastSession, $fastTables, $identifiers[0], $first->eventId()),
                );

                $second = $this->event($type, $encoder);
                $fastStore->materialize($consumers, $second);
                $started = hrtime(true);
                $next = $fastStore->claimBatch($consumers, $encoder, 'replica-fast', '7', 30, 64);
                $elapsed = (hrtime(true) - $started) / 1_000_000_000;
                self::assertLessThan(self::UNRELATED_CLAIM_BUDGET_SECONDS, $elapsed);
                self::assertSame(
                    array_slice($identifiers, 1),
                    $this->consumersOf($next),
                    'New traffic for every consumer but the hung one keeps flowing while its claim is outstanding.',
                );
                foreach ($next as $lease) {
                    self::assertSame($second->eventId(), $lease->event->eventId());
                    $fastStore->complete($lease);
                }
                self::assertSame(
                    'pending',
                    $this->receiptStatus($fastSession, $fastTables, $identifiers[0], $second->eventId()),
                );

                $later = $this->store(
                    $fastSession,
                    $fastTables,
                    $this->clock($clock->now()->modify('+31 seconds')),
                    $contracts,
                );
                $recovered = $later->claimBatch([$consumers[0]], $encoder, 'replica-recovery', '7', 30, 64);
                self::assertCount(1, $recovered, 'The hung receipt is reclaimed once its lease expires.');
                self::assertSame($first->eventId(), $recovered[0]->event->eventId());
                self::assertSame(2, $recovered[0]->attempts);
                $later->complete($recovered[0]);
                self::assertSame(
                    $count * 2 - 2,
                    $this->completed($fastSession, $fastTables, $identifiers),
                    'Every receipt except the poison one and the hung consumer\'s pending second event completed.',
                );
            } finally {
                $this->cleanup($hungSession, $hungTables, $identifiers);
                $fastSession->close();
            }
        }
    }

    /**
     * Open an independent session bound to the drill's contract.
     *
     * @param   string                         $type       Drill event type.
     * @param   list<EventConsumerDefinition>  $consumers  Drill consumer graph.
     *
     * @return  array{Connection, TableNames, EventContractRegistry, ClockInterface}  Session bundle.
     *
     * @since   2.0.0
     */
    private function session(string $type, array $consumers): array
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
        $database = (new DoctrineConnectionFactory($configuration->database))->create();
        $encoder = new DeterministicCanonicalEncoder();
        $contracts = new EventContractRegistry($encoder, [new EventSchemaDefinition(
            $encoder,
            $type,
            1,
            EventSensitivity::INTERNAL,
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        )], $consumers);

        return [
            $database,
            new TableNames($database, $configuration->database->tablePrefix),
            $contracts,
            $this->clock(new DateTimeImmutable('2026-09-24T10:00:00+00:00')),
        ];
    }

    /**
     * Build the production inbox store on a session.
     *
     * @param   Connection             $database   Session.
     * @param   TableNames             $tables     Installation names.
     * @param   ClockInterface         $clock      Lease clock.
     * @param   EventContractRegistry  $contracts  Drill contract.
     *
     * @return  DoctrineInboxStore  Production receipt store.
     *
     * @since   2.0.0
     */
    private function store(
        Connection $database,
        TableNames $tables,
        ClockInterface $clock,
        EventContractRegistry $contracts,
    ): DoctrineInboxStore {
        return new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $clock,
            $contracts,
        );
    }

    /**
     * Create one drill event.
     *
     * @param   string                       $type     Drill event type.
     * @param   DeterministicCanonicalEncoder  $encoder  Canonical encoder.
     *
     * @return  RecordedIntegrationEvent  Validated event.
     *
     * @since   2.0.0
     */
    private function event(string $type, DeterministicCanonicalEncoder $encoder): RecordedIntegrationEvent
    {
        return new RecordedIntegrationEvent(
            $encoder,
            $type,
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-09-24T10:00:00+00:00'),
            null,
            'worker',
            'fanout-drill-site',
            null,
            'acme.record',
            Uuid::uuid7()->toString(),
            1,
            'correlation',
            'cause',
            EventSensitivity::INTERNAL,
            ['id' => 'record'],
        );
    }

    /**
     * Sort the consumer identities of a lease batch.
     *
     * @param   list<InboxLease>  $leases  Claimed leases.
     *
     * @return  list<string>  Consumer identities, ascending.
     *
     * @since   2.0.0
     */
    private function consumersOf(array $leases): array
    {
        $identifiers = array_map(static fn (InboxLease $lease): string => $lease->consumer->identifier(), $leases);
        sort($identifiers);

        return $identifiers;
    }

    /**
     * Read one receipt's status.
     *
     * @param   Connection  $database  Session.
     * @param   TableNames  $tables    Installation names.
     * @param   string      $consumer  Consumer identity.
     * @param   string      $event     Event identity.
     *
     * @return  string  Stored status.
     *
     * @since   2.0.0
     */
    private function receiptStatus(Connection $database, TableNames $tables, string $consumer, string $event): string
    {
        return (string) $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE consumer_id = ? AND event_id = ?',
            $tables->quoted('integration_inbox'),
        ), [$consumer, $event]);
    }

    /**
     * Count the drill's completed receipts.
     *
     * @param   Connection    $database     Session.
     * @param   TableNames    $tables       Installation names.
     * @param   list<string>  $identifiers  Drill consumer identities.
     *
     * @return  int  Completed receipts.
     *
     * @since   2.0.0
     */
    private function completed(Connection $database, TableNames $tables, array $identifiers): int
    {
        return (int) $database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE consumer_id IN (?) AND status = 'completed'",
            $tables->quoted('integration_inbox'),
        ), [$identifiers], [ArrayParameterType::STRING]);
    }

    /**
     * Supply a fixed lease clock.
     *
     * @param   DateTimeImmutable  $now  Fixed instant.
     *
     * @return  ClockInterface  Clock.
     *
     * @since   2.0.0
     */
    private function clock(DateTimeImmutable $now): ClockInterface
    {
        return new class ($now) implements ClockInterface {
            /**
             * Capture the instant.
             *
             * @param  DateTimeImmutable  $instant  Lease clock reading.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly DateTimeImmutable $instant)
            {
            }

            /**
             * Return the instant.
             *
             * @return  DateTimeImmutable  Lease comparison time.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return $this->instant;
            }
        };
    }

    /**
     * Remove the drill's receipts, turns and health rows.
     *
     * @param   Connection    $database     Session.
     * @param   TableNames    $tables       Installation names.
     * @param   list<string>  $identifiers  Drill consumer identities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function cleanup(Connection $database, TableNames $tables, array $identifiers): void
    {
        if ($database->isTransactionActive()) {
            $database->rollBack();
        }
        foreach (['integration_inbox', 'integration_delivery_turns', 'integration_delivery_health'] as $name) {
            $database->executeStatement(
                sprintf('DELETE FROM %s WHERE consumer_id IN (?)', $tables->quoted($name)),
                [$identifiers],
                [ArrayParameterType::STRING],
            );
        }
        $database->close();
    }
}
