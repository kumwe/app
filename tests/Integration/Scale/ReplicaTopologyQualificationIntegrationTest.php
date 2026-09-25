<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Scale;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Automation\JobQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Qualifies the stateless application tier: two independently composed replicas, each with its own
 * connection, container and in-process state, over one authoritative primary.
 *
 * An interactive command committed through one replica is read, replayed and conflict-checked through the
 * other; a job one replica's producer enqueues is run exactly once by whichever replica's worker pool claims
 * it; and both replicas answer readiness from the shared schema state, draining together when that state
 * stops matching their code and returning together when it matches again. The cross-process tests named in
 * docs/operations/scale-topology.md carry the same guarantees for the fence, sequencing, fan-out, queue
 * permit and export budget pools.
 *
 * @since  2.0.0
 */
#[CoversClass(Worker::class)]
#[CoversClass(ReadinessProbe::class)]
#[CoversClass(BusinessRecordService::class)]
final class ReplicaTopologyQualificationIntegrationTest extends TestCase
{
    /**
     * A command committed on one replica is authoritative on the other: read, replayed and conflict-checked.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInteractiveCommandOnOneReplicaIsAuthoritativeOnTheOther(): void
    {
        $first = TestKernelFactory::create(Environment::fromGlobals());
        $second = TestKernelFactory::create(Environment::fromGlobals());
        self::assertNotSame(
            $this->service($first, Connection::class),
            $this->service($second, Connection::class),
            'Each replica holds its own connection.',
        );
        $firstContext = TestKernelFactory::administratorContext($first);
        $secondContext = TestKernelFactory::administratorContext($second);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $definition = NeutralBusinessFixture::install(
            $first,
            $firstContext,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        )->handle;
        $record = Uuid::uuid7()->toString();
        $key = NeutralBusinessFixture::idempotencyKey('replica-create-' . $suffix);
        $created = $this->service($first, BusinessRecordService::class)->create(new CreateRecordCommand(
            $firstContext,
            $definition,
            ['label' => 'Replica qualification ' . $suffix],
            $key,
            recordId: $record,
        ));
        self::assertFalse($created->replayed);

        $secondRecords = $this->service($second, BusinessRecordService::class);
        $read = $secondRecords->read(new ReadRecordQuery($secondContext, $definition, $record));
        self::assertSame(1, $read->version, 'A write on one replica is read after write on the other.');
        $replayed = $secondRecords->create(new CreateRecordCommand(
            $secondContext,
            $definition,
            ['label' => 'Replica qualification ' . $suffix],
            $key,
            recordId: $record,
        ));
        self::assertTrue($replayed->replayed, 'The idempotency ledger is shared, not per replica.');
        self::assertSame($created->recordKey, $replayed->recordKey);

        $updated = $secondRecords->update(new UpdateRecordCommand(
            $secondContext,
            $definition,
            $record,
            1,
            ['label' => 'Replica qualification updated ' . $suffix],
            NeutralBusinessFixture::idempotencyKey('replica-update-' . $suffix),
        ));
        self::assertSame(2, $updated->version);
        $this->expectException(BusinessRecordVersionConflict::class);
        $this->service($first, BusinessRecordService::class)->update(new UpdateRecordCommand(
            $firstContext,
            $definition,
            $record,
            1,
            ['label' => 'Stale replica write ' . $suffix],
            NeutralBusinessFixture::idempotencyKey('replica-stale-' . $suffix),
        ));
    }

    /**
     * A job enqueued by one replica runs once, on whichever replica's pool claims it first.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAJobProducedOnOneReplicaRunsExactlyOnceAcrossBothReplicaPools(): void
    {
        $first = TestKernelFactory::create(Environment::fromGlobals());
        $second = TestKernelFactory::create(Environment::fromGlobals());
        $queue = 'replica_' . bin2hex(random_bytes(6));
        $job = $this->service($first, JobQueue::class)->enqueue(
            TestKernelFactory::administratorContext($first),
            'system.retention.drain',
            ['store' => 'process_history', 'maximum_batches' => 1],
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $queue,
        );
        $secondWorker = $this->service($second, Worker::class);
        $firstWorker = $this->service($first, Worker::class);
        self::assertTrue($secondWorker->runOnce(TestKernelFactory::workerContext($second), $queue, 'replica-b'));
        self::assertFalse($firstWorker->runOnce(TestKernelFactory::workerContext($first), $queue, 'replica-a'));
        self::assertFalse($secondWorker->runOnce(TestKernelFactory::workerContext($second), $queue, 'replica-b'));
        $database = $this->service($first, Connection::class);
        $tables = $this->service($first, TableNames::class);
        self::assertSame('completed', $database->fetchOne(
            sprintf('SELECT status FROM %s WHERE id = ?', $tables->quoted('jobs')),
            [$job],
        ));
        $firstWorker->disconnect(TestKernelFactory::workerContext($first), 'replica-a', $queue);
        $secondWorker->disconnect(TestKernelFactory::workerContext($second), 'replica-b', $queue);
    }

    /**
     * Both replicas answer readiness from the shared schema state and drain and return together.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBothReplicasDrainAndReturnTogetherWithTheSharedSchemaState(): void
    {
        $first = TestKernelFactory::create(Environment::fromGlobals());
        $second = TestKernelFactory::create(Environment::fromGlobals());
        $firstProbe = $this->service($first, ReadinessProbe::class);
        $secondProbe = $this->service($second, ReadinessProbe::class);
        self::assertTrue($firstProbe->ready());
        self::assertTrue($secondProbe->ready());
        $database = $this->service($first, Connection::class);
        $tables = $this->service($first, TableNames::class);
        $version = '99991231235959_replica_qualification_' . bin2hex(random_bytes(4));
        $database->insert($tables->raw('schema_migrations'), [
            'version' => $version,
            'checksum' => str_repeat('0', 64),
            'executed_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'execution_ms' => 0,
        ], ['executed_at' => 'datetime_immutable']);
        try {
            self::assertFalse($firstProbe->ready(), 'A schema newer than the code drains every replica.');
            self::assertFalse($secondProbe->ready());
        } finally {
            $database->delete($tables->raw('schema_migrations'), ['version' => $version]);
        }
        self::assertTrue($firstProbe->ready());
        self::assertTrue($secondProbe->ready());
    }

    /**
     * Resolve one strongly typed service from a replica's container.
     *
     * @template T of object
     *
     * @param   Container        $container  Replica container.
     * @param   class-string<T>  $class      Requested service type.
     *
     * @return  T  Requested service.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $class): object
    {
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
