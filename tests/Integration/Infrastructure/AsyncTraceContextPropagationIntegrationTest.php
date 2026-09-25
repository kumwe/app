<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineProjectionEventSequencer;
use Kumwe\App\Http\Middleware\RequestIdMiddleware;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueue;
use Kumwe\App\Infrastructure\Automation\DoctrineScheduler;
use Kumwe\App\Infrastructure\Observability\CorrelationContext;
use Kumwe\App\Infrastructure\Observability\LogRedactionProcessor;
use Kumwe\App\Infrastructure\Persistence\Migration\AsyncTraceContextMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Automation\JobHandler;
use Kumwe\Automation\JobHandlerRegistry;
use Kumwe\Automation\JobQueue;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Integration\ConsumerIdempotency;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\EventSchemaDefinition;
use Kumwe\Integration\EventSensitivity;
use Kumwe\Integration\RecordedIntegrationEvent;
use Kumwe\Transaction\Contract\TransactionManager;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use ReflectionProperty;
use RuntimeException;

/**
 * Proves correlation, causation and upstream trace context survive every asynchronous boundary on this engine.
 *
 * Each case starts where a real operation starts — an HTTP request carrying a W3C `traceparent`, or a
 * scheduler pass — writes durable work through the wired stores, then claims that work the way the long-running
 * processes do and reads the structured lines the wired logger actually emitted. The lines written while the work
 * runs must carry the originating request's correlation identifier, name that request as their cause and carry its
 * upstream trace identifier, and a failing attempt's line must be redacted like every other.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineJobQueue::class)]
#[CoversClass(Worker::class)]
#[CoversClass(DoctrineScheduler::class)]
#[CoversClass(DoctrineOutboxStore::class)]
#[CoversClass(DoctrineInboxStore::class)]
#[CoversClass(CorrelationContext::class)]
#[CoversClass(RequestIdMiddleware::class)]
#[CoversClass(AsyncTraceContextMigration::class)]
final class AsyncTraceContextPropagationIntegrationTest extends TestCase
{
    /**
     * Upstream W3C trace identifier the simulated proxy sends.
     *
     * @var    string
     * @since  2.0.0
     */
    private const TRACE = '0af7651916cd43dd8448eb211c80319c';

    /**
     * A job queued by an HTTP request runs under that request's correlation, causation and trace, end to end.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAJobQueuedByATracedRequestRunsAndLogsUnderThatRequestsIdentifiers(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $records = self::capture($container);
        $queueName = 'trace-' . bin2hex(random_bytes(4));
        $queue = self::queue($container);
        $requestId = 'trace-request-' . bin2hex(random_bytes(8));
        $jobId = self::enqueueFromTracedRequest($container, $queue, $requestId, $queueName);

        $row = self::connection($container)->fetchAssociative(sprintf(
            'SELECT correlation_id, causation_id, trace_id FROM %s WHERE id = ?',
            self::tables($container)->quoted('jobs'),
        ), [$jobId]);
        self::assertSame(
            ['correlation_id' => $requestId, 'causation_id' => $requestId, 'trace_id' => self::TRACE],
            $row,
        );

        $handler = new TraceRecordingJobHandler(self::logger($container), false);
        $worker = self::workerWith($container, $handler);
        $context = TestKernelFactory::workerContext($container);
        self::assertTrue($worker->runOnce($context, $queueName, 'trace-worker', 30, 30));

        self::assertSame($requestId, $handler->correlationId, 'The job context must join the request.');
        $inside = self::line($records, 'Trace probe job ran.');
        self::assertSame($requestId, $inside->context['correlation_id']);
        self::assertSame($requestId, $inside->context['causation_id']);
        self::assertSame(self::TRACE, $inside->context['trace_id']);
        self::assertSame('worker-job-' . $jobId, $inside->context['request_id']);
        self::assertSame($jobId, $inside->context['job_id']);
        self::assertSame('job', $inside->context['operation']);
        $completed = self::line($records, 'Job completed.');
        self::assertSame($requestId, $completed->context['correlation_id']);
        self::assertSame('success', $completed->context['outcome']);
        self::assertFalse(self::correlation($container)->inside('job'), 'Settlement must close the job frame.');
    }

    /**
     * The migration adds only the missing nullable origin columns, skips an absent table, and replays as a no-op.
     *
     * It runs against private tables under a per-test prefix, one of them already carrying a column as an
     * interrupted earlier pass would have left it, so the installation the rest of the suite shares is untouched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMigrationAddsOnlyMissingNullableColumnsAndReplaysAsANoOp(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = self::connection($container);
        $tables = new TableNames($database, 't' . bin2hex(random_bytes(5)) . '_');
        try {
            $database->executeStatement(sprintf(
                'CREATE TABLE %s (id VARCHAR(36) NOT NULL PRIMARY KEY, correlation_id VARCHAR(191) NULL)',
                $tables->quoted('jobs'),
            ));
            $database->executeStatement(sprintf(
                'CREATE TABLE %s (id VARCHAR(36) NOT NULL PRIMARY KEY)',
                $tables->quoted('integration_outbox'),
            ));
            $migration = new AsyncTraceContextMigration($tables);
            $migration->up($database);
            $migration->up($database);

            $manager = $database->createSchemaManager();
            $jobs = $manager->introspectTableByUnquotedName($tables->raw('jobs'));
            foreach (['correlation_id' => 191, 'causation_id' => 191, 'trace_id' => 32] as $column => $length) {
                self::assertTrue($jobs->hasColumn($column), $column);
                self::assertFalse($jobs->getColumn($column)->getNotnull(), sprintf('%s stays nullable.', $column));
                self::assertSame($length, $jobs->getColumn($column)->getLength(), $column);
            }
            $outbox = $manager->introspectTableByUnquotedName($tables->raw('integration_outbox'));
            self::assertSame(32, $outbox->getColumn('trace_id')->getLength());
            self::assertFalse(
                $manager->tablesExist([$tables->raw('integration_inbox')]),
                'An absent table is skipped, not created.',
            );
            self::assertSame('20260924130000_async_trace_context', $migration->id());
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $migration->checksum());
            self::assertSame($migration->checksum(), (new AsyncTraceContextMigration($tables))->checksum());
        } finally {
            foreach (['jobs', 'integration_outbox'] as $name) {
                $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tables->quoted($name)));
            }
        }
    }

    /**
     * A failing attempt is logged under the request's identifiers with its credential-bearing message redacted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFailingJobAttemptIsLoggedUnderTheRequestAndRedacted(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $records = self::capture($container);
        $queueName = 'trace-fail-' . bin2hex(random_bytes(4));
        $queue = self::queue($container);
        $requestId = 'trace-request-' . bin2hex(random_bytes(8));
        $jobId = self::enqueueFromTracedRequest($container, $queue, $requestId, $queueName);

        $worker = self::workerWith($container, new TraceRecordingJobHandler(self::logger($container), true));
        $context = TestKernelFactory::workerContext($container);
        self::assertTrue($worker->runOnce($context, $queueName, 'trace-worker', 30, 30));

        $failed = self::line($records, 'Job attempt failed; retry scheduled.');
        self::assertSame($requestId, $failed->context['correlation_id']);
        self::assertSame(self::TRACE, $failed->context['trace_id']);
        self::assertSame($jobId, $failed->context['job_id']);
        self::assertSame('retried', $failed->context['outcome']);
        self::assertTrue($failed->context['will_retry']);
        $exception = $failed->context['exception'];
        self::assertIsArray($exception);
        self::assertIsString($exception['message']);
        self::assertStringNotContainsString('patterned-trace-secret', $exception['message']);
        self::assertStringContainsString(LogRedactionProcessor::PLACEHOLDER, $exception['message']);
        self::assertArrayNotHasKey('trace', $exception);
        self::assertFalse(self::correlation($container)->inside('job'));
    }

    /**
     * A row queued before origins were recorded runs under the worker's own correlation, and a reaped final
     * lease is logged as a dead letter under the correlation the row did record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALegacyRowFallsBackToTheWorkerAndAReapedFinalLeaseIsLoggedAsADeadLetter(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $records = self::capture($container);
        $queue = self::queue($container);
        $jobs = self::tables($container)->quoted('jobs');
        $legacyQueue = 'trace-legacy-' . bin2hex(random_bytes(4));
        $legacy = self::enqueueFromTracedRequest($container, $queue, 'trace-request-legacy', $legacyQueue);
        self::connection($container)->executeStatement(sprintf(
            'UPDATE %s SET correlation_id = NULL, causation_id = NULL, trace_id = NULL WHERE id = ?',
            $jobs,
        ), [$legacy]);
        $handler = new TraceRecordingJobHandler(self::logger($container), false);
        $worker = self::workerWith($container, $handler);
        $context = TestKernelFactory::workerContext($container);

        self::assertTrue($worker->runOnce($context, $legacyQueue, 'trace-worker', 30, 30));
        self::assertSame($context->correlationId(), $handler->correlationId);
        $inside = self::line($records, 'Trace probe job ran.');
        self::assertSame($context->correlationId(), $inside->context['correlation_id']);
        self::assertArrayNotHasKey('trace_id', $inside->context);

        $exhaustedQueue = 'trace-exhausted-' . bin2hex(random_bytes(4));
        $exhausted = self::enqueueFromTracedRequest($container, $queue, 'trace-request-exhausted', $exhaustedQueue);
        self::connection($container)->executeStatement(sprintf(
            "UPDATE %s SET status = 'reserved', attempts = maximum_attempts, lease_owner = 'gone', "
            . 'lease_token = ?, lease_acquired_at = ?, lease_expires_at = ? WHERE id = ?',
            $jobs,
        ), [
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('-10 minutes'),
            new DateTimeImmutable('-5 minutes'),
            $exhausted,
        ], [
            \Doctrine\DBAL\Types\Types::STRING,
            \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE,
            \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE,
            \Doctrine\DBAL\Types\Types::GUID,
        ]);

        self::assertFalse($worker->runOnce($context, $exhaustedQueue, 'trace-worker', 30, 30));
        $dead = self::line($records, 'Job dead-lettered after its final lease expired.', ['job_id' => $exhausted]);
        self::assertSame('trace-request-exhausted', $dead->context['correlation_id']);
        self::assertSame('dead', $dead->context['outcome']);
        self::assertSame(400, $dead->level->value);
        self::assertFalse(self::correlation($container)->inside('job'), 'An empty claim must leave no frame.');
    }

    /**
     * A scheduler pass records itself as the origin of every job it queues and logs each occurrence in its frame.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASchedulerPassIsTheRecordedOriginOfTheJobsItQueues(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $records = self::capture($container);
        $scheduler = $container->get(DoctrineScheduler::class);
        self::assertInstanceOf(DoctrineScheduler::class, $scheduler);
        $administrator = TestKernelFactory::administratorContext($container);
        $name = 'Trace probe ' . bin2hex(random_bytes(4));
        $scheduleId = $scheduler->create(
            $administrator,
            $name,
            '* * * * *',
            'UTC',
            'system.sessions.purge',
            [],
            'trace-schedule-' . bin2hex(random_bytes(4)),
            new DateTimeImmutable('-2 minutes'),
        );
        $pass = TestKernelFactory::schedulerContext($container);

        try {
            self::assertGreaterThanOrEqual(1, $scheduler->dispatchDue($pass));
            $row = self::connection($container)->fetchAssociative(sprintf(
                'SELECT id, correlation_id, causation_id FROM %s WHERE schedule_id = ?',
                self::tables($container)->quoted('jobs'),
            ), [$scheduleId]);
            self::assertIsArray($row);
            self::assertSame($pass->correlationId(), $row['correlation_id']);
            self::assertSame($pass->requestId(), $row['causation_id']);
            $dispatched = self::line($records, 'Schedule occurrence dispatched.', ['schedule_id' => $scheduleId]);
            self::assertSame($row['id'], $dispatched->context['job_id']);
            self::assertSame($pass->correlationId(), $dispatched->context['correlation_id']);
            self::assertSame('schedule', $dispatched->context['operation']);
            self::assertFalse(self::correlation($container)->inside('schedule'));
        } finally {
            $version = self::connection($container)->fetchOne(sprintf(
                'SELECT version FROM %s WHERE id = ?',
                self::tables($container)->quoted('schedules'),
            ), [$scheduleId]);
            $scheduler->delete($administrator, $scheduleId, (int) (is_numeric($version) ? $version : 0));
        }
    }

    /**
     * An event produced under a traced request carries that trace through the outbox and into an inbox receipt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEventCarriesTheRequestTraceThroughTheOutboxAndIntoTheInbox(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $records = self::capture($container);
        $correlation = self::correlation($container);
        $transactions = $container->get(TransactionManager::class);
        $clock = $container->get(ClockInterface::class);
        $sequencer = $container->get(DoctrineProjectionEventSequencer::class);
        $canonical = $container->get(CanonicalEncoder::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertInstanceOf(DoctrineProjectionEventSequencer::class, $sequencer);
        self::assertInstanceOf(CanonicalEncoder::class, $canonical);
        $suffix = bin2hex(random_bytes(4));
        $eventType = 'trace.probe' . $suffix . '.raised';
        $consumer = new EventConsumerDefinition(
            'trace.probe' . $suffix . '.consumer',
            $eventType,
            [1],
            '1.0.0',
            'integration.default',
            true,
            ConsumerIdempotency::EVENT_ID,
            3,
        );
        $contracts = new EventContractRegistry(new DeterministicCanonicalEncoder(), [new EventSchemaDefinition(
            new DeterministicCanonicalEncoder(),
            $eventType,
            1,
            EventSensitivity::INTERNAL,
            ['type' => 'object', 'properties' => ['subject' => ['type' => 'string']]],
        )], [$consumer]);
        $outbox = new DoctrineOutboxStore(
            self::connection($container),
            self::tables($container),
            $transactions,
            $clock,
            $contracts,
            $canonical,
            $sequencer,
            correlation: $correlation,
        );
        $inbox = new DoctrineInboxStore(
            self::connection($container),
            self::tables($container),
            $transactions,
            $clock,
            $contracts,
            correlation: $correlation,
        );
        $requestId = 'trace-request-' . $suffix;
        $event = new RecordedIntegrationEvent(
            new DeterministicCanonicalEncoder(),
            $eventType,
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('now'),
            null,
            'trace-probe',
            'default',
            null,
            'trace.probe',
            'subject-' . $suffix,
            1,
            $requestId,
            $requestId,
            EventSensitivity::INTERNAL,
            ['subject' => 'subject-' . $suffix],
        );

        // Start from an empty outbox, as the other claim-order scenarios do: an event another test left
        // pending carries a contract this test's registry does not declare, so claiming it cannot decode it.
        self::connection($container)->executeStatement(sprintf(
            'DELETE FROM %s',
            self::tables($container)->quoted('integration_outbox'),
        ));
        $correlation->begin($requestId, null, self::TRACE, 'b7ad6b7169203331');
        $outbox->append($event);
        $correlation->end();
        self::assertSame(self::TRACE, self::connection($container)->fetchOne(sprintf(
            'SELECT trace_id FROM %s WHERE event_id = ?',
            self::tables($container)->quoted('integration_outbox'),
        ), [$event->eventId()]));

        $lease = null;
        for ($attempt = 0; $attempt < 50 && $lease === null; $attempt++) {
            $candidate = $outbox->claim('trace-dispatcher', 'trace-generation', 30);
            if ($candidate === null) {
                break;
            }
            if ($candidate->event->eventId() === $event->eventId()) {
                $lease = $candidate;
                break;
            }
            // Another test's event: hand it back untouched so its own drill still sees it.
            $outbox->defer($candidate, 1);
        }
        self::assertNotNull($lease, 'The traced event must be claimable.');
        $sequenced = self::line($records, 'Projection sources sequenced.');
        self::assertSame('projection.sequence', $sequenced->context['operation']);
        self::assertGreaterThanOrEqual(1, $sequenced->context['sequenced']);
        self::assertTrue($correlation->inside('outbox'));
        self::assertSame(self::TRACE, $correlation->traceId());
        self::assertSame($requestId, $correlation->correlationId());
        self::assertSame($requestId, $correlation->causationId());
        self::assertSame('outbox-dispatch-' . $event->eventId(), $correlation->requestId());

        // An internal fan-out receives the event while the dispatch frame is open, as the runtime transport does.
        $received = $inbox->receive($consumer, $event, 'trace-consumer', 'trace-generation', 30);
        self::assertNotNull($received->lease);
        self::assertSame(self::TRACE, $inbox->traceOf($received->lease));
        $outbox->complete($lease);
        $inbox->complete($received->lease);

        self::assertFalse($correlation->inside('outbox'), 'Settlement must close the dispatch frame.');
        self::assertSame([], $correlation->fragment());
    }

    /**
     * Enqueue a probe job from inside the real request middleware, with an upstream trace on the request.
     *
     * @param   Container         $container  Kernel under test.
     * @param   DoctrineJobQueue  $queue      Wired queue.
     * @param   string            $requestId  Identifier the simulated client sends as `X-Request-ID`.
     * @param   string            $queueName  Isolated queue name for this case.
     *
     * @return  string  Identifier of the queued job.
     *
     * @since   2.0.0
     */
    private static function enqueueFromTracedRequest(
        Container $container,
        DoctrineJobQueue $queue,
        string $requestId,
        string $queueName,
    ): string {
        $administrator = TestKernelFactory::administratorContext($container);
        $handler = new class ($queue, $administrator, $queueName) implements RequestHandlerInterface {
            /**
             * Identifier of the job the request queued.
             *
             * @var    string
             * @since  2.0.0
             */
            public string $jobId = '';

            /**
             * Bind the handler to the queue and the principal it enqueues as.
             *
             * @param  DoctrineJobQueue  $queue          Wired queue.
             * @param  ExecutionContext  $administrator  Authenticated principal.
             * @param  string            $queueName      Isolated queue name.
             *
             * @since  2.0.0
             */
            public function __construct(
                private readonly DoctrineJobQueue $queue,
                private readonly ExecutionContext $administrator,
                private readonly string $queueName,
            ) {
            }

            /**
             * Queue one job under a context carrying the request identifier, as a real handler would.
             *
             * @param   ServerRequestInterface  $request  Request carrying the resolved identifier attribute.
             *
             * @return  ResponseInterface  An empty accepted response.
             *
             * @since   2.0.0
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $identifier = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);
                if (!is_string($identifier)) {
                    throw new RuntimeException('The request identifier attribute is missing.');
                }
                $this->jobId = $this->queue->enqueue(
                    $this->administrator->child($identifier, $identifier),
                    'system.sessions.purge',
                    ['probe' => 'trace'],
                    new DateTimeImmutable('now'),
                    $this->queueName,
                );

                return new EmptyResponse(202);
            }
        };
        $middleware = new RequestIdMiddleware(self::correlation($container));
        $response = $middleware->process(
            (new ServerRequest([], [], 'https://kumwe.test/api/v1/jobs', 'POST'))
                ->withHeader('X-Request-ID', $requestId)
                ->withHeader('traceparent', '00-' . self::TRACE . '-b7ad6b7169203331-01'),
            $handler,
        );
        self::assertSame($requestId, $response->getHeaderLine('X-Request-ID'));
        self::assertSame([], self::correlation($container)->fragment(), 'The request must close its unit of work.');

        return $handler->jobId;
    }

    /**
     * Rebuild the wired worker around one probe handler, keeping every other collaborator and the origin lookup.
     *
     * @param   Container   $container  Kernel under test.
     * @param   JobHandler  $handler    Handler for the probe job type.
     *
     * @return  Worker  Worker that runs only the probe handler.
     *
     * @since   2.0.0
     */
    private static function workerWith(Container $container, JobHandler $handler): Worker
    {
        $wired = $container->get(Worker::class);
        self::assertInstanceOf(Worker::class, $wired);
        $collaborator = static fn (string $property): mixed
            => (new ReflectionProperty(Worker::class, $property))->getValue($wired);
        $origins = $collaborator('origins');
        self::assertInstanceOf(DoctrineJobQueue::class, $origins, 'The kernel must wire the origin lookup.');
        $queue = $collaborator('queue');
        $authorization = $collaborator('authorization');
        $ownership = $collaborator('ownership');
        $system = $collaborator('system');
        $scope = $collaborator('jobScope');
        $global = $collaborator('globalPrincipals');
        self::assertInstanceOf(JobQueue::class, $queue);
        self::assertInstanceOf(\Kumwe\Access\AuthorizationGateway::class, $authorization);
        self::assertInstanceOf(\Kumwe\Access\ResourceSiteOwnership::class, $ownership);
        self::assertInstanceOf(\Kumwe\App\Application\Authorization\SystemPrincipal::class, $system);
        self::assertInstanceOf(\Kumwe\App\Application\Automation\JobExecutionScope::class, $scope);
        self::assertInstanceOf(\Kumwe\App\Application\Automation\GlobalJobPrincipals::class, $global);

        return new Worker(
            $queue,
            new JobHandlerRegistry([$handler]),
            $authorization,
            $ownership,
            $system,
            $scope,
            $global,
            $origins,
        );
    }

    /**
     * Replace the wired logger's handlers with one that keeps every processed record.
     *
     * @param   Container  $container  Kernel under test.
     *
     * @return  TestHandler  Handler holding the records the wired processors produced.
     *
     * @since   2.0.0
     */
    private static function capture(Container $container): TestHandler
    {
        $records = new TestHandler();
        self::logger($container)->setHandlers([$records]);

        return $records;
    }

    /**
     * Find the first processed record with a message and, optionally, matching context values.
     *
     * @param   TestHandler            $records  Captured records.
     * @param   string                 $message  Message to find.
     * @param   array<string, string>  $where    Context values the record must also carry.
     *
     * @return  LogRecord  The record.
     *
     * @since   2.0.0
     */
    private static function line(TestHandler $records, string $message, array $where = []): LogRecord
    {
        foreach ($records->getRecords() as $record) {
            if (
                $record->message === $message
                && array_intersect_assoc($where, array_map(
                    static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                    $record->context,
                )) === $where
            ) {
                return $record;
            }
        }
        self::fail(sprintf('No "%s" line was written.', $message));
    }

    /**
     * Resolve the wired logger.
     *
     * @param   Container  $container  Kernel under test.
     *
     * @return  Logger  The Monolog logger every subsystem writes through.
     *
     * @since   2.0.0
     */
    private static function logger(Container $container): Logger
    {
        $logger = $container->get(Logger::class);
        self::assertInstanceOf(Logger::class, $logger);

        return $logger;
    }

    /**
     * Resolve the wired queue.
     *
     * @param   Container  $container  Kernel under test.
     *
     * @return  DoctrineJobQueue  The queue the kernel binds to `JobQueue`.
     *
     * @since   2.0.0
     */
    private static function queue(Container $container): DoctrineJobQueue
    {
        $queue = $container->get(JobQueue::class);
        self::assertInstanceOf(DoctrineJobQueue::class, $queue);

        return $queue;
    }

    /**
     * Resolve the shared log-context holder.
     *
     * @param   Container  $container  Kernel under test.
     *
     * @return  CorrelationContext  The holder every processor reads.
     *
     * @since   2.0.0
     */
    private static function correlation(Container $container): CorrelationContext
    {
        $correlation = $container->get(CorrelationContext::class);
        self::assertInstanceOf(CorrelationContext::class, $correlation);

        return $correlation;
    }

    /**
     * Resolve the connection.
     *
     * @param   Container  $container  Kernel under test.
     *
     * @return  Connection  Authoritative connection.
     *
     * @since   2.0.0
     */
    private static function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * Resolve the table names.
     *
     * @param   Container  $container  Kernel under test.
     *
     * @return  TableNames  Prefixed physical names.
     *
     * @since   2.0.0
     */
    private static function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TableNames::class, $tables);

        return $tables;
    }
}

/**
 * Probe job handler that records the context it ran under and writes one line through the wired logger.
 *
 * @since  2.0.0
 */
final class TraceRecordingJobHandler implements JobHandler
{
    /**
     * Correlation identifier of the last context the handler ran under.
     *
     * @var    ?string
     * @since  2.0.0
     */
    public ?string $correlationId = null;

    /**
     * Bind the handler to the wired logger.
     *
     * @param  Logger  $logger  Logger every subsystem writes through.
     * @param  bool    $fails   Whether every attempt raises a credential-bearing failure.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly Logger $logger, private readonly bool $fails)
    {
    }

    /**
     * Answer for the site-scoped job type the probe enqueues.
     *
     * @return  string  Job type.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'system.sessions.purge';
    }

    /**
     * Record the context, log one line, and optionally fail with a message quoting a connection credential.
     *
     * @param   array<string, mixed>  $payload  Probe payload.
     * @param   ExecutionContext      $context  Context the worker built for the job.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the handler was built to fail.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->correlationId = $context->correlationId();
        $this->logger->info('Trace probe job ran.');
        if ($this->fails) {
            throw new RuntimeException(
                'SQLSTATE[08006] connection to pgsql://svc:patterned-trace-secret@db:5432 failed',
            );
        }
    }
}
