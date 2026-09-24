<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessIntegration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotencyState;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordIdempotencyRepository;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineProjectionEventSequencer;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineProjectionStore;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessRecordScaleMigration;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\EventSchemaDefinition;
use Kumwe\Integration\EventSensitivity;
use Kumwe\Integration\RecordedIntegrationEvent;
use Kumwe\Reporting\Domain\ProjectionDefinition;
use Kumwe\Reporting\Contract\ProjectionEvent;
use Kumwe\Reporting\Domain\ProjectionFieldDefinition;
use Kumwe\Reporting\Domain\ProjectionSourceDefinition;
use Kumwe\Reporting\Domain\ReportValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Exercises commit visibility, sequencer arbitration and retention against two actual database sessions.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineOutboxStore::class)]
#[CoversClass(DoctrineProjectionEventSequencer::class)]
#[CoversClass(DoctrineProjectionStore::class)]
#[CoversClass(DoctrineBusinessRecordIdempotencyRepository::class)]
final class ProjectionSequencingConcurrencyIntegrationTest extends TestCase
{
    /**
     * A concurrent lease renewal cannot be erased by retention; another expired row still makes progress.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIdempotencyRetentionSkipsHeldClaimsAndHonorsRenewedLeases(): void
    {
        [$database, $peer, $tables] = $this->sessions();
        $fingerprints = new RecordFingerprint(str_repeat('retention-fixture-', 2));
        $writer = new DoctrineBusinessRecordIdempotencyRepository($database, $tables, $fingerprints);
        $purger = new DoctrineBusinessRecordIdempotencyRepository($peer, $tables, $fingerprints);
        $ids = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        $now = new DateTimeImmutable('1971-01-01T00:00:00+00:00');
        try {
            $database->beginTransaction();
            foreach ($ids as $id) {
                $writer->begin(new BusinessRecordIdempotency(
                    $id,
                    $fingerprints->digest(['id' => $id]),
                    'retention-test-site',
                    null,
                    'retention-test-actor',
                    'business.record.update',
                    $id,
                    $fingerprints->digest(['request' => $id]),
                    $fingerprints->digest(['authorization' => $id]),
                    BusinessRecordIdempotencyState::InProgress,
                    null,
                    null,
                    $this->clock()->now(),
                    null,
                    new DateTimeImmutable('1970-02-01T00:00:00+00:00'),
                ));
            }
            $database->commit();
            $database->beginTransaction();
            $database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE id = ? FOR UPDATE',
                $tables->quoted('business_command_idempotency'),
            ), [$ids[0]]);
            $peer->beginTransaction();
            self::assertSame(1, $purger->purgeExpired($now, 1));
            $peer->commit();
            self::assertSame(2, (int) $peer->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE id IN (?, ?, ?)',
                $tables->quoted('business_command_idempotency'),
            ), $ids), 'The batch budget holds even while an older candidate is locked.');
            $database->update($tables->raw('business_command_idempotency'), [
                'lease_owner' => 'retention-live-owner',
                'lease_expires_at' => '1972-01-01 00:00:00',
            ], ['id' => $ids[0]]);
            $database->commit();
            $peer->beginTransaction();
            self::assertSame(1, $purger->purgeExpired($now, 1));
            self::assertSame(0, $purger->purgeExpired($now, 1));
            $peer->commit();
            self::assertSame($ids[0], $peer->fetchOne(sprintf(
                'SELECT id FROM %s WHERE id IN (?, ?, ?)',
                $tables->quoted('business_command_idempotency'),
            ), $ids));
        } finally {
            foreach ([$database, $peer] as $connection) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
            foreach ($ids as $id) {
                $peer->delete($tables->raw('business_command_idempotency'), ['id' => $id]);
            }
            $database->close();
            $peer->close();
        }
    }

    /**
     * Earlier allocation with a later commit must appear after the checkpoint already read by a projection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLateCommitCannotFallBehindAnObservedProjectionCheckpoint(): void
    {
        [$database, $peer, $tables] = $this->sessions();
        $early = $this->event();
        $late = $this->event();
        $rolledBack = $this->event();
        $writer = $this->outbox($database, $tables);
        $concurrent = $this->outbox($peer, $tables);
        $sequencer = $this->sequencer($peer, $tables);
        $store = new DoctrineProjectionStore(
            $peer,
            $tables,
            new DoctrineTransactionManager($peer),
            $this->clock(),
            new DeterministicCanonicalEncoder(),
            $sequencer,
        );
        try {
            $this->drain($sequencer);
            $database->beginTransaction();
            $writer->append($early);
            $concurrent->append($late);
            $this->drain($sequencer);
            $checkpoint = $store->eventSequence($late->eventId());
            self::assertFalse($peer->fetchOne(sprintf(
                'SELECT source_sequence FROM %s WHERE event_id = ?',
                $tables->quoted('business_projection_source_events'),
            ), [$early->eventId()]));

            $database->commit();
            $page = $store->next($this->definition(), $checkpoint, 1_000);
            self::assertSame([$early->eventId()], array_map(
                static fn (ProjectionEvent $event): string => $event->id(),
                $page,
            ));
            self::assertGreaterThan($checkpoint, $page[0]->sequence());
            $database->beginTransaction();
            $writer->append($rolledBack);
            $database->rollBack();
            $this->drain($sequencer);
            foreach (
                [
                'integration_outbox', 'business_projection_event_staging', 'business_projection_source_events',
                ] as $name
            ) {
                self::assertFalse($peer->fetchOne(sprintf(
                    'SELECT event_id FROM %s WHERE event_id = ?',
                    $tables->quoted($name),
                ), [$rolledBack->eventId()]));
            }
        } finally {
            $this->cleanup($database, $peer, $tables, [$early, $late, $rolledBack]);
        }
    }

    /**
     * A sequencer holding the singleton cannot block an authoritative append or a competing sequencer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHeadContentionDoesNotEnterAuthoritativeWritesOrDuplicatePublication(): void
    {
        [$database, $peer, $tables] = $this->sessions();
        $event = $this->event();
        $sequencer = $this->sequencer($peer, $tables);
        try {
            $this->drain($sequencer);
            $database->beginTransaction();
            $database->fetchOne(sprintf(
                'SELECT last_sequence FROM %s WHERE singleton_id = 1 FOR UPDATE',
                $tables->quoted('business_projection_event_head'),
            ));
            $this->outbox($peer, $tables)->append($event);
            self::assertSame(0, $sequencer->sequence());
            self::assertFalse($peer->fetchOne(sprintf(
                'SELECT event_id FROM %s WHERE event_id = ?',
                $tables->quoted('business_projection_source_events'),
            ), [$event->eventId()]));
            $database->rollBack();
            self::assertSame(1, $sequencer->sequence());
            self::assertSame(0, $this->sequencer($database, $tables)->sequence());
            self::assertSame(1, (int) $peer->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE event_id = ?',
                $tables->quoted('business_projection_source_events'),
            ), [$event->eventId()]));
        } finally {
            $this->cleanup($database, $peer, $tables, [$event]);
        }
    }

    /**
     * Retention skips a held terminal row, preserves unsequenced facts and respects a replay transition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetentionCannotEraseStagingOrAConcurrentReplay(): void
    {
        [$database, $peer, $tables] = $this->sessions();
        $first = $this->event();
        $second = $this->event();
        $outbox = $this->outbox($peer, $tables);
        $now = new DateTimeImmutable('1971-01-01T00:00:00+00:00');
        try {
            foreach ([$first, $second] as $event) {
                $outbox->append($event);
                $peer->update($tables->raw('integration_outbox'), ['status' => 'dispatched'], [
                    'event_id' => $event->eventId(),
                ]);
            }
            self::assertSame(0, $outbox->purgeExpired($now, 2), 'A terminal flag does not authorize losing staging.');
            $this->drain($this->sequencer($peer, $tables));
            $database->beginTransaction();
            $database->fetchOne(sprintf(
                'SELECT event_id FROM %s WHERE event_id = ? FOR UPDATE',
                $tables->quoted('integration_outbox'),
            ), [$first->eventId()]);
            self::assertSame(1, $outbox->purgeExpired($now, 1));
            self::assertFalse($peer->fetchOne(sprintf(
                'SELECT event_id FROM %s WHERE event_id = ?',
                $tables->quoted('integration_outbox'),
            ), [$second->eventId()]));
            $this->outbox($database, $tables)->replay($first->eventId(), 'scale-test-operator');
            $database->commit();
            self::assertSame(0, $outbox->purgeExpired($now, 2));
            self::assertSame('pending', $peer->fetchOne(sprintf(
                'SELECT status FROM %s WHERE event_id = ?',
                $tables->quoted('integration_outbox'),
            ), [$first->eventId()]));
        } finally {
            $this->cleanup($database, $peer, $tables, [$first, $second]);
        }
    }

    /**
     * Open separately owned sessions with a short wait limit so a hidden global mutex fails the test.
     *
     * @return  array{Connection, Connection, TableNames}  Writer, rival and installation names.
     *
     * @since   2.0.0
     */
    private function sessions(): array
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
        $connections = new DoctrineConnectionFactory($configuration->database);
        $database = $connections->create();
        $peer = $connections->create();
        $names = new TableNames($peer, $configuration->database->tablePrefix);
        (new BusinessIntegrationSdkMigration($names))->up($database);
        (new BusinessRecordScaleMigration($names))->up($database);
        foreach ([$database, $peer] as $connection) {
            $connection->executeStatement($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET SESSION innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '1s'");
        }

        return [$database, $peer, new TableNames($peer, $names->prefix())];
    }

    /**
     * Bind the production outbox to independent test contracts without changing the live runtime catalog.
     *
     * @param   Connection  $database  Writer or dispatch session.
     * @param   TableNames  $tables    Shared installation names.
     *
     * @return  DoctrineOutboxStore  Production persistence adapter.
     *
     * @since   2.0.0
     */
    private function outbox(Connection $database, TableNames $tables): DoctrineOutboxStore
    {
        $encoder = new DeterministicCanonicalEncoder();
        $contracts = new EventContractRegistry($encoder, [new EventSchemaDefinition(
            $encoder,
            'acme.scale.changed',
            1,
            EventSensitivity::INTERNAL,
            ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]],
        )], []);

        return new DoctrineOutboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $this->clock(),
            $contracts,
            $encoder,
            $this->sequencer($database, $tables),
        );
    }

    /**
     * Construct a sequencer using the session whose transaction it owns.
     *
     * @param   Connection  $database  Independent database session.
     * @param   TableNames  $tables    Installation names.
     *
     * @return  DoctrineProjectionEventSequencer  Production committed-source sequencer.
     *
     * @since   2.0.0
     */
    private function sequencer(Connection $database, TableNames $tables): DoctrineProjectionEventSequencer
    {
        return new DoctrineProjectionEventSequencer($database, $tables, new DoctrineTransactionManager($database));
    }

    /**
     * Drain bounded fixture pages before asserting one event's exact sequence transition.
     *
     * @param   DoctrineProjectionEventSequencer  $sequencer  Session outside an authoritative transaction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function drain(DoctrineProjectionEventSequencer $sequencer): void
    {
        for ($batch = 0; $batch < 100; ++$batch) {
            if ($sequencer->sequence(1_000) < 1_000) {
                return;
            }
        }
        self::fail('The bounded fixture drain did not settle.');
    }

    /**
     * Create a unique independent aggregate fact for the sequencing fixture.
     *
     * @return  RecordedIntegrationEvent  Validated fixture event.
     *
     * @since   2.0.0
     */
    private function event(): RecordedIntegrationEvent
    {
        return new RecordedIntegrationEvent(
            new DeterministicCanonicalEncoder(),
            'acme.scale.changed',
            1,
            Uuid::uuid7()->toString(),
            $this->clock()->now(),
            'scale-test-actor',
            null,
            'scale-test-site',
            null,
            'acme.scale',
            Uuid::uuid7()->toString(),
            1,
            'scale-correlation',
            'scale-request',
            EventSensitivity::INTERNAL,
            ['value' => 'fixture'],
        );
    }

    /**
     * Describe the test-only projection's exact source scope.
     *
     * @return  ProjectionDefinition  Bounded declaration consumed by the production reader.
     *
     * @since   2.0.0
     */
    private function definition(): ProjectionDefinition
    {
        return new ProjectionDefinition(
            'acme.scale_projection',
            1,
            '1.0.0',
            EventSensitivity::INTERNAL,
            [new ProjectionSourceDefinition('acme.scale.changed', [1])],
            [new ProjectionFieldDefinition('value', ReportValueType::String)],
            ['value'],
            100,
        );
    }

    /**
     * Date these rows outside other fixtures' expiry ranges so retention assertions are isolated.
     *
     * @return  ClockInterface  Fixed lifecycle clock.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            /**
             * Return the fixture's fixed creation instant.
             *
             * @return  DateTimeImmutable  UTC test instant.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('1970-01-01T00:00:00+00:00');
            }
        };
    }

    /**
     * Release transactions and remove only facts owned by this test, leaving the monotonic head intact.
     *
     * @param   Connection                      $database  First session.
     * @param   Connection                      $peer      Second session.
     * @param   TableNames                      $tables    Shared installation names.
     * @param   list<RecordedIntegrationEvent>  $events    Exactly this test's fixture rows.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function cleanup(Connection $database, Connection $peer, TableNames $tables, array $events): void
    {
        foreach ([$database, $peer] as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        foreach ($events as $event) {
            foreach (
                [
                'integration_outbox', 'business_projection_event_staging', 'business_projection_source_events',
                ] as $name
            ) {
                $peer->delete($tables->raw($name), ['event_id' => $event->eventId()]);
            }
        }
        $database->close();
        $peer->close();
    }
}
