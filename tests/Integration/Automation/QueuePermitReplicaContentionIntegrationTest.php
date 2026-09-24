<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Automation;

use DateTimeImmutable;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\EventSchemaDefinition;
use Kumwe\Integration\EventSensitivity;
use Kumwe\Integration\RecordedIntegrationEvent;
use Psr\Clock\ClockInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Automation\DoctrineQueuePermits;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueueFairness;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\Migration\QueueWorkerPermitsMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\Automation\QueueRuntimePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Uses two real engine connections to prove hot-path skip-locking, strict capacity and durable fair turns.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineQueuePermits::class)]
#[CoversClass(DoctrineJobQueueFairness::class)]
#[CoversClass(QueueWorkerPermitsMigration::class)]
final class QueuePermitReplicaContentionIntegrationTest extends TestCase
{
    /**
     * Prove a busy policy row or occupied permit cannot serialize unrelated replica claims.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReplicasSkipHeldPermitsAndNeverExceedTheSharedCeiling(): void
    {
        [$primary, $replica, $tables] = $this->fixture();
        $a = new DoctrineQueuePermits($primary, $tables);
        $b = new DoctrineQueuePermits($replica, new TableNames($replica, $tables->prefix()));
        $policy = new QueueRuntimePolicy('acme.work', 60, 5, 2, 7, 9);
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        try {
            $a->synchronize($policy, $now);
            $primary->beginTransaction();
            $primary->fetchOne(sprintf(
                'SELECT queue_id FROM %s WHERE queue_id = ? FOR UPDATE',
                $tables->quoted('job_queue_runtime')
            ), [$policy->queue]);
            $first = Uuid::uuid7()->toString();
            self::assertTrue($a->acquire($policy, $now, 'job', $first, '', $first, $now->modify('+60 seconds')));
            // This must not try to lock the held policy row for an unchanged generation.
            $b->synchronize($policy, $now);
            $second = Uuid::uuid7()->toString();
            self::assertTrue($replica->transactional(static fn (): bool => $b->acquire(
                $policy,
                $now,
                'inbox',
                $second,
                'acme.consumer',
                $second,
                $now->modify('+60 seconds'),
            )));
            self::assertFalse($replica->transactional(static fn (): bool => $b->acquire(
                $policy,
                $now,
                'job',
                $second,
                '',
                Uuid::uuid7()->toString(),
                $now->modify('+60 seconds'),
            )));
            $primary->commit();
            self::assertSame(2, (int) $replica->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE lease_expires_at > ?',
                $tables->quoted('job_queue_permits'),
            ), [$now], [Types::DATETIME_IMMUTABLE]));
            $primary->transactional(static fn () => $a->release($policy->queue, $first));
            self::assertTrue($replica->transactional(static fn (): bool => $b->acquire(
                $policy,
                $now,
                'job',
                $first,
                '',
                Uuid::uuid7()->toString(),
                $now->modify('+60 seconds'),
            )));
        } finally {
            $this->cleanup($primary, $replica, $tables);
        }
    }

    /**
     * Prove a locked tenant lane is skipped and sustained new work cannot starve another organization.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFairTenantTurnsSurviveIndependentReplicasAndBacklog(): void
    {
        [$primary, $replica, $tables] = $this->fixture();
        $a = new DoctrineJobQueueFairness($primary, $tables);
        $b = new DoctrineJobQueueFairness($replica, new TableNames($replica, $tables->prefix()));
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        try {
            foreach (['org-a', 'org-b', 'org-c'] as $organization) {
                for ($index = 0; $index < ($organization === 'org-a' ? 20 : 1); $index++) {
                    $id = Uuid::uuid7()->toString();
                    $primary->insert($tables->raw('jobs'), [
                        'id' => $id, 'queue' => 'acme.work', 'status' => 'pending',
                        'execution_scope' => 'installation', 'available_at' => $now,
                    ], ['available_at' => Types::DATETIME_IMMUTABLE]);
                    $a->record('acme.work', $id, 'site-a', $organization);
                }
            }
            $primary->beginTransaction();
            $first = $a->claim('acme.work', $now);
            self::assertNotNull($first);
            $second = $replica->transactional(static fn (): ?string => $b->claim('acme.work', $now));
            self::assertNotNull($second);
            self::assertNotSame($first, $second);
            $primary->commit();
            $third = $replica->transactional(static fn (): ?string => $b->claim('acme.work', $now));
            self::assertNotContains($third, [$first, $second]);
            $served = [$first, $second, $third];
            sort($served);
            $expected = array_map(
                static fn (string $org): string => hash('sha256', "site-a\0" . $org),
                ['org-a', 'org-b', 'org-c']
            );
            sort($expected);
            self::assertSame($expected, $served);
        } finally {
            $this->cleanup($primary, $replica, $tables);
        }
    }

    /**
     * Prove a held consumer permit cannot block a different consumer or leak capacity through another tenant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConsumerPermitsSkipBusyReplicasAcrossTenantLanes(): void
    {
        [$primary, $replica, $tables] = $this->fixture();
        $encoder = new DeterministicCanonicalEncoder();
        $consumers = [
            new EventConsumerDefinition('acme.first', 'acme.changed', [1], '1.0.0', 'acme.work', false),
            new EventConsumerDefinition('acme.second', 'acme.changed', [1], '1.0.0', 'acme.work', false),
        ];
        $contracts = new EventContractRegistry($encoder, [new EventSchemaDefinition(
            $encoder,
            'acme.changed',
            1,
            EventSensitivity::INTERNAL,
            ['type' => 'object'],
        )], $consumers);
        $clock = new class implements ClockInterface {
            /**
             * Return a fixed instant for both independent connections.
             *
             * @return  DateTimeImmutable  Lease comparison instant.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-24T10:00:00+00:00');
            }
        };
        $a = new DoctrineInboxStore($primary, $tables, new DoctrineTransactionManager($primary), $clock, $contracts);
        $b = new DoctrineInboxStore(
            $replica,
            new TableNames($replica, $tables->prefix()),
            new DoctrineTransactionManager($replica),
            $clock,
            $contracts
        );
        try {
            // Initialize committed health rows without eligible work; preparation is an exceptional write.
            self::assertSame([], $a->claimBatch($consumers, $encoder, 'replica-a', '9', 30));
            foreach (['org-a', 'org-b'] as $organization) {
                $event = new RecordedIntegrationEvent(
                    $encoder,
                    'acme.changed',
                    1,
                    Uuid::uuid7()->toString(),
                    $clock->now(),
                    null,
                    'worker',
                    'default',
                    $organization,
                    'acme.record',
                    Uuid::uuid7()->toString(),
                    1,
                    'correlation',
                    'cause',
                    EventSensitivity::INTERNAL,
                    ['id' => 'record'],
                );
                $a->materialize($consumers, $event);
            }
            $primary->beginTransaction();
            $first = $a->claimBatch($consumers, $encoder, 'replica-a', '9', 30);
            self::assertCount(1, $first);
            $second = $b->claimBatch($consumers, $encoder, 'replica-b', '9', 30, 10);
            self::assertCount(1, $second);
            self::assertNotSame($first[0]->consumer->identifier(), $second[0]->consumer->identifier());
            self::assertSame([], $b->claimBatch($consumers, $encoder, 'replica-b', '9', 30, 10));
            $primary->commit();
            $a->complete($first[0]);
            $next = $b->claimBatch($consumers, $encoder, 'replica-b', '9', 30, 10);
            self::assertCount(1, $next);
            self::assertSame($first[0]->consumer->identifier(), $next[0]->consumer->identifier());
            self::assertNotSame($first[0]->event->organizationId(), $next[0]->event->organizationId());
        } finally {
            $this->cleanup($primary, $replica, $tables);
        }
    }

    /**
     * Kill a real claimant before and after commit, then prove rollback or bounded expiry restores capacity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSigkillReleasesUncommittedPermitsAndExpiresCommittedOnes(): void
    {
        foreach ([false, true] as $commit) {
            [$primary, $replica, $tables] = $this->fixture();
            $policy = new QueueRuntimePolicy('acme.work', 5, 5, 1, 7, 9);
            $now = new DateTimeImmutable();
            $permits = new DoctrineQueuePermits($primary, $tables);
            $permits->synchronize($policy, $now);
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($sockets);
            $token = Uuid::uuid7()->toString();
            $pid = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                try {
                    // Never use the inherited parent sockets: the child owns a fresh engine session.
                    $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
                    $connection = (new DoctrineConnectionFactory($configuration->database))->create();
                    $child = new DoctrineQueuePermits($connection, new TableNames($connection, $tables->prefix()));
                    $connection->beginTransaction();
                    if (!$child->acquire($policy, $now, 'job', $token, '', $token, $now->modify('+5 seconds'))) {
                        throw new \RuntimeException('The child did not acquire the only permit.');
                    }
                    if ($commit) {
                        $connection->commit();
                    }
                    fwrite($sockets[1], "claimed\n");
                    // Parent kills only after the database write and the explicit readiness handshake.
                    while (true) {
                        usleep(100_000);
                    }
                } catch (\Throwable $failure) {
                    fwrite($sockets[1], $failure->getMessage() . "\n");
                    // Do not run inherited connection destructors, which could close parent sessions.
                    posix_kill(getmypid(), SIGKILL);
                    exit(1);
                }
            }
            fclose($sockets[1]);
            $reaped = false;
            try {
                stream_set_timeout($sockets[0], 5);
                self::assertSame("claimed\n", fgets($sockets[0]));
                self::assertTrue(posix_kill($pid, SIGKILL));
                self::assertSame($pid, pcntl_waitpid($pid, $status));
                $reaped = true;
                self::assertTrue(pcntl_wifsignaled($status));
                self::assertSame(SIGKILL, pcntl_wtermsig($status));
                $replacement = Uuid::uuid7()->toString();
                $rival = new DoctrineQueuePermits($replica, new TableNames($replica, $tables->prefix()));
                $claim = static fn (DateTimeImmutable $at): bool => $replica->transactional(
                    static fn (): bool => $rival->acquire(
                        $policy,
                        $at,
                        'inbox',
                        $replacement,
                        'acme.consumer',
                        $replacement,
                        $at->modify('+5 seconds'),
                    ),
                );
                self::assertSame(!$commit, $claim(new DateTimeImmutable()));
                if ($commit) {
                    $deadline = microtime(true) + 7.0;
                    do {
                        usleep(50_000);
                        $recovered = $claim(new DateTimeImmutable());
                    } while (!$recovered && microtime(true) < $deadline);
                    self::assertTrue($recovered, 'The wall clock must reclaim capacity within the lease bound.');
                }
                $primary->transactional(static fn () => $permits->release($policy->queue, $token));
                self::assertSame($replacement, $primary->fetchOne(sprintf(
                    'SELECT lease_token FROM %s WHERE queue_id = ?',
                    $tables->quoted('job_queue_permits'),
                ), [$policy->queue]));
            } finally {
                fclose($sockets[0]);
                if (!$reaped) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
                $this->cleanup($primary, $replica, $tables);
            }
        }
    }

    /**
     * Create a minimal schema with unique physical names, leaving the shared application schema alone.
     *
     * @return  array{Connection, Connection, TableNames}  Independent connections and this test's owned names.
     *
     * @since   2.0.0
     */
    private function fixture(): array
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
        $factory = new DoctrineConnectionFactory($configuration->database);
        $primary = $factory->create();
        $replica = $factory->create();
        foreach ([$primary, $replica] as $connection) {
            $connection->executeStatement($configuration->database->driver === 'pgsql'
                ? "SET lock_timeout = '2s'" : 'SET SESSION innodb_lock_wait_timeout = 2');
        }
        $tables = new TableNames($primary, 'qp_' . substr(str_replace('-', '', Uuid::uuid7()->toString()), -8) . '_');
        $specifications = [
            'jobs' => ['id' => Types::GUID, 'queue' => Types::STRING, 'status' => Types::STRING,
                'lease_token' => Types::GUID, 'lease_expires_at' => Types::DATETIME_IMMUTABLE,
                'worker_scope' => Types::STRING, 'execution_scope' => Types::STRING,
                'available_at' => Types::DATETIME_IMMUTABLE],
            'integration_inbox' => ['consumer_id' => Types::STRING, 'event_id' => Types::GUID,
                'queue' => Types::STRING, 'status' => Types::STRING, 'lease_token' => Types::GUID,
                'lease_expires_at' => Types::DATETIME_IMMUTABLE, 'site_identifier' => Types::STRING,
                'organization_id' => Types::STRING, 'event_type' => Types::STRING,
                'schema_version' => Types::INTEGER, 'handler_version' => Types::STRING,
                'aggregate_type' => Types::STRING, 'aggregate_id' => Types::STRING,
                'aggregate_version' => Types::BIGINT, 'envelope' => Types::JSON,
                'attempts' => Types::INTEGER, 'maximum_attempts' => Types::INTEGER,
                'available_at' => Types::DATETIME_IMMUTABLE, 'lease_owner' => Types::STRING,
                'lease_acquired_at' => Types::DATETIME_IMMUTABLE, 'runtime_generation' => Types::STRING,
                'failure_classification' => Types::STRING, 'exception_type' => Types::STRING,
                'error_message' => Types::TEXT, 'first_received_at' => Types::DATETIME_IMMUTABLE,
                'completed_at' => Types::DATETIME_IMMUTABLE, 'evidence_compacted_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE],
            'job_queue_runtime' => ['queue_id' => Types::STRING, 'lease_seconds' => Types::INTEGER,
                'maximum_attempts' => Types::INTEGER, 'maximum_in_flight' => Types::INTEGER,
                'retention_days' => Types::INTEGER, 'runtime_generation' => Types::BIGINT,
                'last_claimed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE],
            'resource_site_ownership' => ['resource_type' => Types::STRING, 'resource_id' => Types::STRING,
                'site_identifier' => Types::STRING],
            'sites' => ['identifier' => Types::STRING, 'enabled' => Types::BOOLEAN],
        ];
        foreach ($specifications as $name => $columns) {
            $table = new Table($tables->raw($name));
            $identity = array_key_first($columns);
            foreach ($columns as $column => $type) {
                $options = ['notnull' => $column === $identity];
                if ($type === Types::STRING) {
                    $options['length'] = 191;
                }
                $table->addColumn($column, $type, $options);
            }
            $key = PrimaryKeyConstraint::editor()->setUnquotedColumnNames(
                ...($name === 'integration_inbox' ? ['consumer_id', 'event_id'] : [$identity]),
            )->create();
            $table->addPrimaryKeyConstraint($key);
            foreach ($primary->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
                $primary->executeStatement($sql);
            }
        }
        (new QueueWorkerPermitsMigration($tables))->up($primary);
        return [$primary, $replica, $tables];
    }

    /**
     * Roll back open claims and remove only this test's uniquely prefixed tables.
     *
     * @param   Connection  $primary  First independent session.
     * @param   Connection  $replica  Second independent session.
     * @param   TableNames  $tables   Names owned by this fixture.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function cleanup(Connection $primary, Connection $replica, TableNames $tables): void
    {
        foreach ([$primary, $replica] as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        foreach (
            ['job_queue_permits', 'job_queue_turns', 'integration_delivery_turns', 'integration_delivery_health',
            'job_queue_runtime',
            'integration_inbox', 'jobs', 'resource_site_ownership', 'sites'] as $name
        ) {
            $primary->executeStatement('DROP TABLE ' . $tables->quoted($name));
        }
        $primary->close();
        $replica->close();
    }
}
