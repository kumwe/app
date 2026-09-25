<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Retention;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Application\Retention\RetentionBudget;
use Kumwe\App\Application\Retention\RetentionDrain;
use Kumwe\App\Application\Retention\RetentionDrainResult;
use Kumwe\App\Application\Retention\RetentionObserver;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\BusinessReporting\Application\ExportArtifactStorage;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Retention\DoctrineRetentionDrain;
use Kumwe\App\Infrastructure\Retention\DoctrineRetentionObserver;
use Kumwe\App\Infrastructure\Retention\RetentionRunLedger;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Audit\Application\AuditRetentionService;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyPurger;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\Automation\QueueRuntimePolicy;
use Kumwe\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Idempotency\IdempotencyPurger;
use Kumwe\Integration\OutboxStore;
use Kumwe\Transaction\Contract\TransactionManager;
use Psr\Clock\ClockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves what each generic retention drain removes, keeps and refuses on the configured engine.
 *
 * Every store has its own survival rule: a settled job leaves with its dead-letter and ownership rows, a
 * process keeps its instance while its settled work goes, an export row leaves only with its stored object,
 * a delivery receipt is compacted before it is tombstoned and survives while its outbox source exists, and
 * the audit trail is pruned only through its own enabled schedule. Each case seeds rows dated far outside
 * the store's window beside rows that must survive, and cleans up whatever the drain was right to keep.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineRetentionDrain::class)]
#[CoversClass(DoctrineRetentionObserver::class)]
#[CoversClass(RetentionRunLedger::class)]
final class RetentionStoreDrainIntegrationTest extends TestCase
{
    /**
     * A settled day far outside every store's window.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ANCIENT = '2000-01-01 00:00:00';

    /**
     * Sessions are refused by the generic drain, and a store that is never drained finishes without work.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSessionsAreRefusedAndAnUndrainableStoreIsRecordedAsClearWithoutWork(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $drain = $this->service($container, RetentionDrain::class);
        $context = TestKernelFactory::workerContext($container);

        try {
            $drain->drain(RetentionStore::Sessions, new RetentionBudget(30, 10, 100, 250), $context);
            self::fail('Sessions are drained per site by their own job, never by the generic drain.');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame(
                'Sessions are drained per site by system.sessions.purge.',
                $refusal->getMessage(),
            );
        }

        $result = $drain->drain(RetentionStore::Revisions, new RetentionBudget(30, 10, 100, 250), $context);
        self::assertSame(0, $result->rowsDrained);
        self::assertSame(0, $result->batches);
        self::assertTrue($result->backlogCleared);
        self::assertFalse($result->budgetExhausted);
        $this->assertRecorded($container, $result);
    }

    /**
     * Settled core jobs leave with their dead letters and ownership rows; live and recent jobs stay.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSettledJobHistoryLeavesWithItsDeadLettersAndOwnershipWhileLiveJobsStay(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $completed = $this->insertJob($database, $tables, 'completed', self::ANCIENT, self::ANCIENT);
        $dead = $this->insertJob($database, $tables, 'dead', self::ANCIENT, null);
        $pending = $this->insertJob($database, $tables, 'pending', self::ANCIENT, null);
        $recent = $this->insertJob($database, $tables, 'completed', 'now', 'now');
        $database->insert($tables->raw('failed_jobs'), [
            'id' => Uuid::uuid7()->toString(), 'job_id' => $dead, 'queue' => 'default',
            'job_type' => 'acme.retention.probe', 'schema_version' => 1, 'payload' => '{}', 'attempts' => 5,
            'maximum_attempts' => 5, 'failure_classification' => 'permanent', 'exception_type' => 'RuntimeException',
            'error_message' => 'retention probe', 'failed_at' => new DateTimeImmutable(self::ANCIENT),
            'created_at' => new DateTimeImmutable(self::ANCIENT),
        ], ['failed_at' => Types::DATETIME_IMMUTABLE, 'created_at' => Types::DATETIME_IMMUTABLE]);
        $database->insert($tables->raw('resource_site_ownership'), [
            'resource_type' => 'job', 'resource_id' => $dead, 'site_identifier' => 'default', 'scope_level' => 'site',
        ]);
        try {
            $result = $this->drain($container, RetentionStore::JobHistory);

            self::assertGreaterThanOrEqual(2, $result->rowsDrained);
            self::assertTrue($result->backlogCleared);
            self::assertSame(
                [$pending, $recent],
                $this->surviving($database, $tables, 'jobs', 'id', [$completed, $dead, $pending, $recent]),
            );
            self::assertSame([], $this->surviving($database, $tables, 'failed_jobs', 'job_id', [$dead]));
            self::assertSame(
                [],
                $this->surviving($database, $tables, 'resource_site_ownership', 'resource_id', [$dead]),
            );
            $this->assertRecorded($container, $result);
            $again = $this->drain($container, RetentionStore::JobHistory);
            self::assertSame(0, $again->rowsDrained, 'A drained history has nothing left to remove.');
            self::assertTrue($again->backlogCleared);
        } finally {
            $database->executeStatement(sprintf(
                'DELETE FROM %s WHERE id IN (?)',
                $tables->quoted('jobs'),
            ), [[$pending, $recent]], [ArrayParameterType::STRING]);
        }
    }

    /**
     * Settled work items of a process leave while the process instance and its live work stay.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSettledProcessWorkLeavesWhileTheInstanceAndLiveWorkStay(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $processId = Uuid::uuid7()->toString();
        $database->insert($tables->raw('business_process_instances'), [
            'process_id' => $processId, 'process_type' => 'acme.retention.probe',
            'correlation_id' => 'retention-' . $processId, 'site_identifier' => 'default', 'version' => 1,
            'status' => 'running', 'state' => '{}', 'created_at' => new DateTimeImmutable(self::ANCIENT),
            'updated_at' => new DateTimeImmutable(self::ANCIENT),
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $work = [];
        foreach (['completed', 'dead', 'canceled', 'pending'] as $status) {
            $work[$status] = Uuid::uuid7()->toString();
            $database->insert($tables->raw('business_process_work'), [
                'work_id' => $work[$status], 'process_id' => $processId, 'process_version' => 1,
                'work_kind' => 'step', 'work_name' => 'retention-' . $status, 'payload' => '{}',
                'due_at' => new DateTimeImmutable(self::ANCIENT), 'status' => $status, 'maximum_attempts' => 3,
                'created_at' => new DateTimeImmutable(self::ANCIENT),
                'updated_at' => new DateTimeImmutable(self::ANCIENT),
            ], [
                'due_at' => Types::DATETIME_IMMUTABLE, 'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }
        try {
            $result = $this->drain($container, RetentionStore::ProcessHistory);

            self::assertGreaterThanOrEqual(3, $result->rowsDrained);
            self::assertSame(
                [$work['pending']],
                $this->surviving($database, $tables, 'business_process_work', 'work_id', array_values($work)),
            );
            self::assertSame(
                [$processId],
                $this->surviving($database, $tables, 'business_process_instances', 'process_id', [$processId]),
            );
            self::assertSame(0, $this->drain($container, RetentionStore::ProcessHistory)->rowsDrained);
        } finally {
            $database->delete($tables->raw('business_process_work'), ['process_id' => $processId]);
            $database->delete($tables->raw('business_process_instances'), ['process_id' => $processId]);
        }
    }

    /**
     * An expired export row leaves only with its stored object; an unexpired export keeps both.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExpiredExportLeavesWithItsStoredObjectAndAnUnexpiredOneKeepsBoth(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $storage = $this->service($container, ExportArtifactStorage::class);
        $expired = Uuid::uuid7()->toString();
        $live = Uuid::uuid7()->toString();
        $keyless = Uuid::uuid7()->toString();
        $expiredObject = $storage->store($expired, ["name\nexpired\n"]);
        $liveObject = $storage->store($live, ["name\nlive\n"]);
        foreach (
            [
                $expired => [self::ANCIENT, ['storage_key' => $expiredObject->key]],
                $live => ['+1 day', ['storage_key' => $liveObject->key]],
                $keyless => [self::ANCIENT, ['state' => 'failed']],
            ] as $artifactId => [$expiresAt, $document]
        ) {
            $encoded = json_encode($document, JSON_THROW_ON_ERROR);
            $database->insert($tables->raw('business_report_export_artifacts'), [
                'artifact_id' => $artifactId, 'version' => 1, 'status' => 'completed',
                'site_identifier' => 'default', 'actor_id' => 'retention-probe',
                'expires_at' => new DateTimeImmutable($expiresAt), 'document' => $encoded,
                'document_checksum' => hash('sha256', $encoded),
            ], ['expires_at' => Types::DATETIME_IMMUTABLE]);
        }
        try {
            $result = $this->drain($container, RetentionStore::ExportArtifacts);

            self::assertGreaterThanOrEqual(2, $result->rowsDrained);
            self::assertSame([$live], $this->surviving(
                $database,
                $tables,
                'business_report_export_artifacts',
                'artifact_id',
                [$expired, $live, $keyless],
            ));
            try {
                $storage->open($expiredObject);
                self::fail('The expired export object must be deleted with its row.');
            } catch (RuntimeException $missing) {
                self::assertSame('Export artifact storage integrity failed.', $missing->getMessage());
            }
            $stream = $storage->open($liveObject);
            self::assertIsResource($stream);
            fclose($stream);
        } finally {
            $database->delete($tables->raw('business_report_export_artifacts'), ['artifact_id' => $live]);
            $storage->delete($liveObject->key);
        }
    }

    /**
     * Settled receipts are compacted after a day and tombstoned only past the window without an outbox row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSettledReceiptsAreCompactedThenTombstonedOnlyOnceTheirOutboxSourceIsGone(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $consumer = 'acme.retention.receipt-' . bin2hex(random_bytes(4));
        $ancient = $this->insertReceipt($database, $tables, $consumer, 'completed', self::ANCIENT);
        $sourced = $this->insertReceipt($database, $tables, $consumer, 'poison', self::ANCIENT);
        $recent = $this->insertReceipt($database, $tables, $consumer, 'completed', '-2 days');
        $pending = $this->insertReceipt($database, $tables, $consumer, 'pending', self::ANCIENT);
        $database->insert($tables->raw('integration_outbox'), [
            'event_id' => $sourced, 'event_type' => 'acme.retention.receipt', 'schema_version' => 1,
            'sensitivity' => 'internal', 'site_identifier' => 'retention-drill', 'aggregate_type' => 'acme',
            'aggregate_id' => $sourced, 'aggregate_version' => 1, 'correlation_id' => 'retention', 'envelope' => '{}',
            'status' => 'pending', 'available_at' => new DateTimeImmutable('2999-01-01'), 'attempts' => 0,
            'maximum_attempts' => 1, 'retained_until' => new DateTimeImmutable('2999-01-01'), 'replay_count' => 0,
            'created_at' => new DateTimeImmutable(self::ANCIENT), 'updated_at' => new DateTimeImmutable(self::ANCIENT),
        ], [
            'available_at' => Types::DATETIME_IMMUTABLE, 'retained_until' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        try {
            // One-row batches: a batch filled by compaction alone ends before any tombstone is considered.
            $result = $this->drain(
                $container,
                RetentionStore::InboxReceipts,
                budget: new RetentionBudget(30, 1, 1, 250),
            );

            self::assertGreaterThanOrEqual(3, $result->rowsDrained);
            $rows = $database->fetchAllAssociativeIndexed(sprintf(
                'SELECT event_id, envelope, lease_owner, error_message, evidence_compacted_at FROM %s '
                . 'WHERE consumer_id = ?',
                $tables->quoted('integration_inbox'),
            ), [$consumer]);
            self::assertArrayNotHasKey($ancient, $rows, 'A settled receipt past the window without a source goes.');
            self::assertSame([$sourced, $recent, $pending], array_values(array_filter(
                [$ancient, $sourced, $recent, $pending],
                static fn (string $eventId): bool => array_key_exists($eventId, $rows),
            )));
            foreach ([$sourced, $recent] as $compacted) {
                self::assertSame('{}', $rows[$compacted]['envelope']);
                self::assertNull($rows[$compacted]['lease_owner']);
                self::assertNull($rows[$compacted]['error_message']);
                self::assertNotNull($rows[$compacted]['evidence_compacted_at']);
            }
            self::assertNotSame('{}', $rows[$pending]['envelope'], 'A live receipt keeps its evidence.');
            self::assertNull($rows[$pending]['evidence_compacted_at']);
        } finally {
            $database->delete($tables->raw('integration_outbox'), ['event_id' => $sourced]);
            $database->delete($tables->raw('integration_inbox'), ['consumer_id' => $consumer]);
        }
    }

    /**
     * The audit drain and observation follow only the audit schedule, and an undecodable payload is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAuditDrainFollowsOnlyItsOwnEnabledRetentionSchedule(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $schedule = $database->fetchAssociative(sprintf(
            'SELECT payload, enabled FROM %s WHERE job_type = ?',
            $tables->quoted('schedules'),
        ), ['audit.retention.enforce']);
        self::assertIsArray($schedule, 'The audit retention schedule is seeded disabled.');
        $positions = static fn (): string => (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $tables->quoted('audit_events'),
        ));
        $operator = TestKernelFactory::administratorContext($container);
        $enabled = in_array($schedule['enabled'], [true, 1, '1', 't', 'true'], true) ? 1 : 0;
        try {
            $this->schedule($database, $tables, 0, '{"retention_days":30}');
            $disabled = $this->drain($container, RetentionStore::Audit, $operator);
            self::assertSame(0, $disabled->rowsDrained);
            self::assertTrue($disabled->backlogCleared);

            $this->schedule($database, $tables, 1, '"thirty days"');
            try {
                $this->drain($container, RetentionStore::Audit, $operator);
                self::fail('An enabled schedule whose payload is not an object must be refused.');
            } catch (RuntimeException $refusal) {
                self::assertSame(
                    'The audit retention schedule payload is not decodable.',
                    $refusal->getMessage(),
                );
            }

            $this->schedule($database, $tables, 1, '{"retention_days":0}');
            self::assertSame(0, $this->drain($container, RetentionStore::Audit, $operator)->rowsDrained);

            $unconfigured = $this->service($container, RetentionObserver::class)->observe(RetentionStore::Audit);
            self::assertFalse($unconfigured->configured, 'A zero-day window leaves audit retention unconfigured.');

            $before = $positions();
            $this->schedule($database, $tables, 1, '{"retention_days":36500}');
            $inWindow = $this->drain($container, RetentionStore::Audit, $operator);
            self::assertSame(0, $inWindow->rowsDrained, 'Nothing is older than a hundred years.');
            self::assertSame($before, $positions());
            $configured = $this->service($container, RetentionObserver::class)->observe(RetentionStore::Audit);
            self::assertTrue($configured->configured, implode('; ', $configured->settingProblems));
            self::assertSame(0, $configured->backlogRows, 'The enabled window is probed and finds nothing due.');
        } finally {
            $database->update($tables->raw('schedules'), [
                'payload' => $schedule['payload'],
                'enabled' => $enabled,
            ], ['job_type' => 'audit.retention.enforce']);
        }
    }

    /**
     * The journal drain never passes the lowest live projection checkpoint, and stops when nothing is due.
     *
     * A projection still building from the journal needs every row after its checkpoint. The drain bounds
     * itself by the lowest checkpoint of any active or building generation — whichever is lower, a
     * generation recorded here or one the installation already had — so the row past that floor survives
     * even though it is as old as the rows that go.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheJournalDrainStopsAtTheLowestLiveProjectionCheckpoint(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $first = (int) $database->fetchOne(sprintf(
            'SELECT COALESCE(MAX(source_sequence), 0) FROM %s',
            $tables->quoted('business_projection_source_events'),
        )) + 2_000_000;
        $events = [];
        foreach ([0, 1, 2] as $offset) {
            $eventId = Uuid::uuid7()->toString();
            $events[$first + $offset] = $eventId;
            $database->insert($tables->raw('business_projection_source_events'), [
                'source_sequence' => $first + $offset, 'event_id' => $eventId,
                'event_type' => 'acme.retention.floor', 'schema_version' => 1, 'sensitivity' => 'internal',
                'envelope' => '{}', 'event_checksum' => str_repeat('a', 64),
                'recorded_at' => new DateTimeImmutable(self::ANCIENT),
            ], ['recorded_at' => Types::DATETIME_IMMUTABLE]);
        }
        $existing = $database->fetchOne(sprintf(
            "SELECT MIN(last_sequence) FROM %s WHERE status IN ('active', 'building')",
            $tables->quoted('business_projection_generations'),
        ));
        $floor = $first + 1;
        $effective = is_numeric($existing) ? min((int) $existing, $floor) : $floor;
        $generation = Uuid::uuid7()->toString();
        $database->insert($tables->raw('business_projection_generations'), [
            'generation_id' => $generation, 'projection_id' => 'acme.retention.floor-' . substr($generation, -12),
            'definition_checksum' => str_repeat('b', 64), 'handler_version' => '1', 'status' => 'building',
            'last_sequence' => $floor, 'source_checksum' => str_repeat('c', 64),
            'created_at' => new DateTimeImmutable(self::ANCIENT), 'updated_at' => new DateTimeImmutable(self::ANCIENT),
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        try {
            $this->drain($container, RetentionStore::SequencedJournal);
            $expected = [];
            foreach ($events as $sequence => $eventId) {
                if ($sequence > $effective) {
                    $expected[] = $eventId;
                }
            }
            self::assertSame($expected, $this->surviving(
                $database,
                $tables,
                'business_projection_source_events',
                'event_id',
                array_values($events),
            ), 'Every row past the lowest live checkpoint survives; the rest are removed.');
            self::assertContains($events[$first + 2], $expected);
            self::assertSame(0, $this->drain($container, RetentionStore::SequencedJournal)->rowsDrained);
        } finally {
            $database->delete($tables->raw('business_projection_generations'), ['generation_id' => $generation]);
            $database->executeStatement(sprintf(
                'DELETE FROM %s WHERE source_sequence >= ?',
                $tables->quoted('business_projection_source_events'),
            ), [$first]);
        }
    }

    /**
     * Settled jobs of a contributed queue are left to that queue's own signed retention window.
     *
     * Contributed queues declare their retention with their runtime policy and are drained by the queue
     * runtime operations. The generic drain must not apply the core window to them as well, or a job could
     * be removed earlier than the package that owns it promised.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSettledJobsOfAContributedQueueAreLeftToItsOwnWindow(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $queues = self::createStub(QueueRuntimePolicyCatalog::class);
        $queues->method('policies')->willReturn([new QueueRuntimePolicy('acme.contributed', 60, 5, 1, 90, 1)]);
        $drain = new DoctrineRetentionDrain(
            $database,
            $tables,
            $this->service($container, TransactionManager::class),
            $this->service($container, ClockInterface::class),
            $this->service($container, RetentionCatalogue::class),
            $this->service($container, BusinessRecordIdempotencyPurger::class),
            $this->service($container, IdempotencyPurger::class),
            $this->service($container, OutboxStore::class),
            $this->service($container, AuditRetentionService::class),
            $this->service($container, ExportArtifactStorage::class),
            $queues,
            $this->service($container, RetentionRunLedger::class),
        );
        $core = $this->insertJob($database, $tables, 'completed', self::ANCIENT, self::ANCIENT);
        $contributed = $this->insertJob($database, $tables, 'completed', self::ANCIENT, self::ANCIENT);
        $database->update($tables->raw('jobs'), ['queue' => 'acme.contributed'], ['id' => $contributed]);
        try {
            $result = $drain->drain(
                RetentionStore::JobHistory,
                new RetentionBudget(30, 100, 1_000, 250),
                TestKernelFactory::workerContext($container),
            );

            self::assertGreaterThanOrEqual(1, $result->rowsDrained);
            self::assertSame(
                [$contributed],
                $this->surviving($database, $tables, 'jobs', 'id', [$core, $contributed]),
                'Only the core queue job is removed by the generic drain.',
            );
        } finally {
            $database->executeStatement(sprintf(
                'DELETE FROM %s WHERE id IN (?)',
                $tables->quoted('jobs'),
            ), [[$core, $contributed]], [ArrayParameterType::STRING]);
        }
    }

    /**
     * Rewrite the audit retention schedule's switch and payload.
     *
     * @param   Connection  $database  Session.
     * @param   TableNames  $tables    Installation names.
     * @param   int         $enabled   One to enable the schedule, zero to disable it.
     * @param   string      $payload   Stored payload text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function schedule(Connection $database, TableNames $tables, int $enabled, string $payload): void
    {
        $database->update(
            $tables->raw('schedules'),
            ['payload' => $payload, 'enabled' => $enabled],
            ['job_type' => 'audit.retention.enforce'],
        );
    }

    /**
     * Drain one store under a generous budget, as the worker unless another actor is named.
     *
     * @param   Container          $container  Booted kernel.
     * @param   RetentionStore     $store      Store to drain.
     * @param   ?ExecutionContext  $context    Actor running the drain, the queue worker when omitted.
     * @param   ?RetentionBudget   $budget     Budget of the run, a generous one when omitted.
     *
     * @return  RetentionDrainResult  Outcome of the run.
     *
     * @since   2.0.0
     */
    private function drain(
        Container $container,
        RetentionStore $store,
        ?ExecutionContext $context = null,
        ?RetentionBudget $budget = null,
    ): RetentionDrainResult {
        return $this->service($container, RetentionDrain::class)->drain(
            $store,
            $budget ?? new RetentionBudget(30, 100, 1_000, 250),
            $context ?? TestKernelFactory::workerContext($container),
        );
    }

    /**
     * Require the run ledger to hold the result just produced for its store.
     *
     * @param   Container             $container  Booted kernel.
     * @param   RetentionDrainResult  $result     Result the drain returned.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRecorded(Container $container, RetentionDrainResult $result): void
    {
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $row = $database->fetchAssociative(sprintf(
            'SELECT rows_drained, batches, backlog_cleared, budget_exhausted FROM %s WHERE store = ? '
            . 'ORDER BY ran_at DESC LIMIT 1',
            $tables->quoted('retention_runs'),
        ), [$result->store->value]);
        self::assertIsArray($row);
        self::assertSame($result->rowsDrained, (int) $row['rows_drained']);
        self::assertSame($result->batches, (int) $row['batches']);
        self::assertSame($result->backlogCleared, (bool) $row['backlog_cleared']);
        self::assertSame($result->budgetExhausted, (bool) $row['budget_exhausted']);
    }

    /**
     * Insert one core-queue job in a chosen state.
     *
     * @param   Connection   $database     Session.
     * @param   TableNames   $tables       Installation names.
     * @param   string       $status       Job status.
     * @param   string       $updatedAt    Last change, as a date expression.
     * @param   string|null  $completedAt  Completion time, as a date expression, or null.
     *
     * @return  string  Job identity.
     *
     * @since   2.0.0
     */
    private function insertJob(
        Connection $database,
        TableNames $tables,
        string $status,
        string $updatedAt,
        ?string $completedAt,
    ): string {
        $id = Uuid::uuid7()->toString();
        $database->insert($tables->raw('jobs'), [
            'id' => $id, 'queue' => 'default', 'job_type' => 'acme.retention.probe', 'schema_version' => 1,
            'payload' => '{}', 'priority' => 0, 'status' => $status,
            'available_at' => new DateTimeImmutable($updatedAt), 'attempts' => 0, 'maximum_attempts' => 5,
            'completed_at' => $completedAt === null ? null : new DateTimeImmutable($completedAt),
            'created_at' => new DateTimeImmutable($updatedAt), 'updated_at' => new DateTimeImmutable($updatedAt),
        ], [
            'available_at' => Types::DATETIME_IMMUTABLE, 'completed_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);

        return $id;
    }

    /**
     * Insert one delivery receipt carrying lease and failure evidence.
     *
     * @param   Connection  $database   Session.
     * @param   TableNames  $tables     Installation names.
     * @param   string      $consumer   Consumer identity unique to the test.
     * @param   string      $status     Receipt status.
     * @param   string      $updatedAt  Last change, as a date expression.
     *
     * @return  string  Event identity of the receipt.
     *
     * @since   2.0.0
     */
    private function insertReceipt(
        Connection $database,
        TableNames $tables,
        string $consumer,
        string $status,
        string $updatedAt,
    ): string {
        $eventId = Uuid::uuid7()->toString();
        $database->insert($tables->raw('integration_inbox'), [
            'consumer_id' => $consumer, 'event_id' => $eventId, 'queue' => 'integration',
            'event_type' => 'acme.retention.receipt', 'schema_version' => 1, 'handler_version' => '1',
            'site_identifier' => 'retention-drill', 'aggregate_type' => 'acme', 'aggregate_id' => $eventId,
            'aggregate_version' => 1, 'envelope' => '{"event_id":"' . $eventId . '"}', 'status' => $status,
            'attempts' => 1, 'maximum_attempts' => 3, 'available_at' => new DateTimeImmutable($updatedAt),
            'lease_owner' => 'worker-retention', 'error_message' => 'kept evidence',
            'first_received_at' => new DateTimeImmutable($updatedAt),
            'updated_at' => new DateTimeImmutable($updatedAt),
        ], [
            'available_at' => Types::DATETIME_IMMUTABLE, 'first_received_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);

        return $eventId;
    }

    /**
     * List which of the given identities still exist, in the order given.
     *
     * @param   Connection    $database  Session.
     * @param   TableNames    $tables    Installation names.
     * @param   string        $table     Logical table.
     * @param   string        $column    Identity column.
     * @param   list<string>  $ids       Identities to look for.
     *
     * @return  list<string>  Surviving identities.
     *
     * @since   2.0.0
     */
    private function surviving(
        Connection $database,
        TableNames $tables,
        string $table,
        string $column,
        array $ids,
    ): array {
        $present = $database->fetchFirstColumn(sprintf(
            'SELECT %s FROM %s WHERE %s IN (?)',
            $column,
            $tables->quoted($table),
            $column,
        ), [$ids], [ArrayParameterType::STRING]);

        return array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $present, true)));
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
