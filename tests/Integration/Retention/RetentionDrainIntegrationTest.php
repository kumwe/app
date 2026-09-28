<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Retention;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Retention\RetentionBudget;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Application\Retention\RetentionDrain;
use Kumwe\App\Application\Retention\RetentionObserver;
use Kumwe\App\Application\Retention\RetentionReadiness;
use Kumwe\App\Application\Retention\RetentionReadinessState;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Infrastructure\Persistence\Migration\RetentionCatalogueMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Retention\DoctrineRetentionDrain;
use Kumwe\App\Infrastructure\Retention\DoctrineRetentionObserver;
use Kumwe\App\Infrastructure\Retention\RetentionRunLedger;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the budgeted drain, the bounded observer and the seeded schedules on the configured engine.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineRetentionDrain::class)]
#[CoversClass(DoctrineRetentionObserver::class)]
#[CoversClass(RetentionRunLedger::class)]
#[CoversClass(RetentionCatalogueMigration::class)]
final class RetentionDrainIntegrationTest extends TestCase
{
    /**
     * A seeded backlog is observed, drained in adaptive batches, recorded, and observed again as drained.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExpiredIdempotencyBacklogIsObservedDrainedAndRecorded(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $observer = $this->service($container, RetentionObserver::class);
        $drain = $this->service($container, RetentionDrain::class);
        $ids = $this->seedExpiredClaims($database, $tables, 2_500);
        $live = $this->seedExpiredClaims($database, $tables, 1, '+1 day');

        $before = $observer->observe(RetentionStore::BusinessIdempotency);
        self::assertGreaterThanOrEqual(2_500, $before->backlogRows);
        self::assertNotNull($before->oldestEligibleAgeSeconds);
        self::assertGreaterThan(3_000.0, $before->oldestEligibleAgeSeconds);
        self::assertTrue($before->configured, implode('; ', $before->settingProblems));

        $result = $drain->drain(
            RetentionStore::BusinessIdempotency,
            new RetentionBudget(30, 100, 1_000, 250),
            TestKernelFactory::workerContext($container),
        );
        self::assertTrue($result->backlogCleared);
        self::assertFalse($result->budgetExhausted);
        self::assertGreaterThanOrEqual(2_500, $result->rowsDrained);
        self::assertGreaterThan(1, $result->batches);
        self::assertGreaterThan(100, $result->finalBatch, 'A fast engine grows the batch past its initial size.');
        self::assertSame(0, $this->remaining($database, $tables, $ids));
        self::assertSame(1, $this->remaining($database, $tables, $live), 'An unexpired claim survives.');

        $after = $observer->observe(RetentionStore::BusinessIdempotency);
        self::assertSame(0, $after->backlogRows);
        self::assertNotNull($after->drainRowsPerSecond);
        self::assertGreaterThan(0.0, $after->drainRowsPerSecond);
        self::assertNotNull($after->lastDrainAt);
        $database->delete($tables->raw('business_command_idempotency'), ['id' => $live[0]]);
    }

    /**
     * A time budget stops a run that still has work, and the stop is reported as exhaustion, not clearance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABatchCapEndsTheRunAsBudgetExhaustion(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $ids = $this->seedExpiredClaims($database, $tables, 300);
        $result = $this->service($container, RetentionDrain::class)->drain(
            RetentionStore::BusinessIdempotency,
            new RetentionBudget(30, 100, 100, 250, 2),
            TestKernelFactory::workerContext($container),
        );
        self::assertSame(200, $result->rowsDrained);
        self::assertTrue($result->budgetExhausted);
        self::assertFalse($result->backlogCleared);
        $database->executeStatement(sprintf(
            'DELETE FROM %s WHERE id IN (?)',
            $tables->quoted('business_command_idempotency'),
        ), [$ids], [ArrayParameterType::STRING]);
    }

    /**
     * Every store is observed with a bounded probe, the seeded schedules configure the generic stores, and
     * only audit is left unconfigured by default, which the baseline profile reports as a warning.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryStoreIsObservableAndTheShippedDefaultsOnlyWarnAboutAudit(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $observations = $this->service($container, RetentionObserver::class)->observeAll();
        self::assertCount(count(RetentionStore::cases()), $observations);
        $unconfigured = [];
        foreach ($observations as $observation) {
            if (!$observation->configured) {
                $unconfigured[] = $observation->store->value;
            }
            self::assertLessThanOrEqual(DoctrineRetentionObserver::PROBE_CAP, $observation->backlogRows);
        }
        self::assertSame(['audit'], $unconfigured);
        $verdict = (new RetentionReadiness())->assess($observations, false);
        self::assertSame(RetentionReadinessState::Warning, $verdict->state);
        $enterprise = (new RetentionReadiness())->assess($observations, true);
        self::assertSame(RetentionReadinessState::Failed, $enterprise->state);
        self::assertNotSame([], $this->service($container, RetentionCatalogue::class)->policies());
    }

    /**
     * The journal drain never removes a row whose outbox row still exists, so replay always finds its source.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheJournalDrainKeepsRowsWhoseOutboxSourceStillExists(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $sequence = (int) $database->fetchOne(sprintf(
            'SELECT COALESCE(MAX(source_sequence), 0) FROM %s',
            $tables->quoted('business_projection_source_events'),
        )) + 1_000_000;
        $kept = Uuid::uuid7()->toString();
        $gone = Uuid::uuid7()->toString();
        foreach ([$kept => $sequence, $gone => $sequence + 1] as $eventId => $position) {
            $database->insert($tables->raw('business_projection_source_events'), [
                'source_sequence' => $position, 'event_id' => $eventId, 'event_type' => 'acme.retention.journal',
                'schema_version' => 1, 'sensitivity' => 'internal', 'envelope' => '{}',
                'event_checksum' => str_repeat('a', 64), 'recorded_at' => new DateTimeImmutable('2000-01-01'),
            ], ['recorded_at' => Types::DATETIME_IMMUTABLE]);
        }
        $database->insert($tables->raw('integration_outbox'), [
            'event_id' => $kept, 'event_type' => 'acme.retention.journal', 'schema_version' => 1,
            'sensitivity' => 'internal', 'site_identifier' => 'retention-drill', 'aggregate_type' => 'acme',
            'aggregate_id' => $kept, 'aggregate_version' => 1, 'correlation_id' => 'retention', 'envelope' => '{}',
            'status' => 'pending', 'available_at' => new DateTimeImmutable('2999-01-01'), 'attempts' => 0,
            'maximum_attempts' => 1, 'retained_until' => new DateTimeImmutable('2999-01-01'), 'replay_count' => 0,
            'created_at' => new DateTimeImmutable('2000-01-01'), 'updated_at' => new DateTimeImmutable('2000-01-01'),
        ], [
            'available_at' => Types::DATETIME_IMMUTABLE, 'retained_until' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        try {
            $this->service($container, RetentionDrain::class)->drain(
                RetentionStore::SequencedJournal,
                new RetentionBudget(30, 100, 1_000, 250),
                TestKernelFactory::workerContext($container),
            );
            $left = $database->fetchFirstColumn(sprintf(
                'SELECT event_id FROM %s WHERE event_id IN (?, ?)',
                $tables->quoted('business_projection_source_events'),
            ), [$kept, $gone]);
            self::assertSame([$kept], $left);
        } finally {
            $database->delete($tables->raw('integration_outbox'), ['event_id' => $kept]);
            $database->executeStatement(sprintf(
                'DELETE FROM %s WHERE event_id IN (?, ?)',
                $tables->quoted('business_projection_source_events'),
            ), [$kept, $gone]);
        }
    }

    /**
     * Insert completed business idempotency claims with a chosen expiry.
     *
     * @param   Connection  $database  Session.
     * @param   TableNames  $tables    Installation names.
     * @param   int         $count     Claims to insert.
     * @param   string      $expiry    Relative expiry; the default is an hour ago.
     *
     * @return  list<string>  Inserted identities.
     *
     * @since   2.0.0
     */
    private function seedExpiredClaims(
        Connection $database,
        TableNames $tables,
        int $count,
        string $expiry = '-1 hour',
    ): array {
        $ids = [];
        $now = new DateTimeImmutable();
        $rows = [];
        $parameters = [];
        for ($index = 0; $index < $count; $index++) {
            $id = Uuid::uuid7()->toString();
            $ids[] = $id;
            $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $parameters,
                $id,
                hash('sha256', 'retention-scope-' . $id),
                'retention-drill',
                'system:retention-drill',
                'business.record.update',
                $id,
                str_repeat('b', 64),
                str_repeat('c', 64),
                'completed',
                '{}',
                str_repeat('d', 64),
                $now->modify('-2 hours')->format('Y-m-d H:i:s'),
                $now->modify($expiry)->format('Y-m-d H:i:s'),
            );
            if (count($rows) === 200 || $index === $count - 1) {
                $database->executeStatement(sprintf(
                    'INSERT INTO %s (id, scope_digest, site_identifier, actor_id, operation, operation_id, '
                    . 'request_fingerprint, authorization_fingerprint, state, result, result_checksum, created_at, '
                    . 'expires_at) VALUES %s',
                    $tables->quoted('business_command_idempotency'),
                    implode(', ', $rows),
                ), $parameters);
                $rows = [];
                $parameters = [];
            }
        }

        return $ids;
    }

    /**
     * Count which of the given claims still exist.
     *
     * @param   Connection    $database  Session.
     * @param   TableNames    $tables    Installation names.
     * @param   list<string>  $ids       Claim identities.
     *
     * @return  int  Surviving claims.
     *
     * @since   2.0.0
     */
    private function remaining(Connection $database, TableNames $tables, array $ids): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE id IN (?)',
            $tables->quoted('business_command_idempotency'),
        ), [$ids], [ArrayParameterType::STRING]);
    }

    /**
     * Resolve a typed service.
     *
     * @template T of object
     *
     * @param   Container        $container  Booted kernel.
     * @param   class-string<T>  $id         Service identity.
     *
     * @return  T  Service.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $id): object
    {
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
