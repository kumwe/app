<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Automation;

use DateTimeImmutable;
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
        $tables = new TableNames($primary, 'qp_' . substr(str_replace('-', '', Uuid::uuid7()->toString()), -8) . '_');
        $specifications = [
            'jobs' => ['id' => Types::GUID, 'queue' => Types::STRING, 'status' => Types::STRING,
                'lease_token' => Types::GUID, 'lease_expires_at' => Types::DATETIME_IMMUTABLE,
                'worker_scope' => Types::STRING, 'execution_scope' => Types::STRING,
                'available_at' => Types::DATETIME_IMMUTABLE],
            'integration_inbox' => ['consumer_id' => Types::STRING, 'event_id' => Types::GUID,
                'queue' => Types::STRING, 'status' => Types::STRING, 'lease_token' => Types::GUID,
                'lease_expires_at' => Types::DATETIME_IMMUTABLE, 'site_identifier' => Types::STRING,
                'organization_id' => Types::STRING],
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
                $table->addColumn($column, $type, ['notnull' => $column === $identity]);
            }
            $key = PrimaryKeyConstraint::editor()->setUnquotedColumnNames($identity)->create();
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
            ['job_queue_permits', 'job_queue_turns', 'integration_delivery_turns', 'job_queue_runtime',
            'integration_inbox', 'jobs', 'resource_site_ownership', 'sites'] as $name
        ) {
            $primary->executeStatement('DROP TABLE ' . $tables->quoted($name));
        }
        $primary->close();
        $replica->close();
    }
}
