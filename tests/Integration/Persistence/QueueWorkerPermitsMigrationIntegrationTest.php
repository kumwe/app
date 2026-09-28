<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueueFairness;
use Kumwe\App\Infrastructure\Persistence\Migration\QueueWorkerPermitsMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Drives the queue worker permit migration as an upgrade of a legacy installation on the configured engine.
 *
 * The shared installation met this migration before any test ran, so it proves only its end state. What an
 * upgrade must also do is proven here, on private tables: resume a deployment that stopped after creating
 * the permit table but before its later column and index, give every legacy job a fairness lane from the
 * ownership table that remains the authority, give every unsettled legacy receipt a delivery turn for its
 * exact site and organization, and do all of it once — a replay changes nothing.
 *
 * @since  2.0.0
 */
#[CoversClass(QueueWorkerPermitsMigration::class)]
#[CoversClass(DoctrineJobQueueFairness::class)]
final class QueueWorkerPermitsMigrationIntegrationTest extends TestCase
{
    /**
     * An interrupted legacy upgrade is completed and backfilled exactly once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInterruptedLegacyUpgradeIsCompletedAndBackfilledExactlyOnce(): void
    {
        $database = $this->connection();
        $tables = new TableNames(
            $database,
            'q' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10) . '_',
        );
        $this->createLegacyTables($database, $tables);
        $owned = Uuid::uuid7()->toString();
        $unowned = Uuid::uuid7()->toString();
        foreach ([$owned => 'default', $unowned => 'acme.imports'] as $jobId => $queue) {
            $database->insert($tables->raw('jobs'), [
                'id' => $jobId, 'queue' => $queue, 'status' => 'pending',
                'available_at' => new DateTimeImmutable('2026-09-24T10:00:00+00:00'),
            ], ['available_at' => Types::DATETIME_IMMUTABLE]);
        }
        $database->insert($tables->raw('resource_site_ownership'), [
            'resource_type' => 'job', 'resource_id' => $owned, 'site_identifier' => 'default',
        ]);
        foreach (
            [
                ['pending', null],
                ['reserved', 'org-7'],
                ['completed', 'org-9'],
            ] as [$status, $organization]
        ) {
            $database->insert($tables->raw('integration_inbox'), [
                'consumer_id' => 'acme.consumer', 'event_id' => Uuid::uuid7()->toString(),
                'site_identifier' => 'default', 'organization_id' => $organization, 'status' => $status,
            ]);
        }

        try {
            $migration = new QueueWorkerPermitsMigration($tables);
            self::assertSame('20260924030000_queue_worker_permits', $migration->id());
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
            $migration->up($database);
            $migration->up($database);

            $manager = $database->createSchemaManager();
            $permits = $manager->introspectTableByUnquotedName($tables->raw('job_queue_permits'));
            self::assertTrue($permits->hasColumn('last_claimed_at'), 'The interrupted column is added.');
            self::assertTrue($permits->hasIndex(
                'idx_queue_lease_' . substr(hash('sha256', $tables->raw('job_queue_permits')), 0, 16),
            ));
            self::assertTrue($manager->introspectTableByUnquotedName($tables->raw('jobs'))->hasIndex(
                'idx_job_turn_' . substr(hash('sha256', $tables->raw('jobs')), 0, 16),
            ));
            self::assertSame(
                [
                    $owned => hash('sha256', 'default' . "\0"),
                    $unowned => hash('sha256', "\0"),
                ],
                $this->pairs($database, sprintf(
                    'SELECT id, worker_scope FROM %s ORDER BY id',
                    $tables->quoted('jobs'),
                )),
                'Each legacy job joins the lane of the site its ownership row names.',
            );
            self::assertSame(
                [
                    'acme.imports' => hash('sha256', "\0"),
                    'default' => hash('sha256', 'default' . "\0"),
                ],
                $this->pairs($database, sprintf(
                    'SELECT queue_id, scope_key FROM %s ORDER BY queue_id',
                    $tables->quoted('job_queue_turns'),
                )),
            );
            self::assertSame(
                [
                    hash('sha256', 'default' . "\0") => '',
                    hash('sha256', 'default' . "\0" . 'org-7') => 'org-7',
                ],
                $this->pairs($database, sprintf(
                    'SELECT scope_checksum, organization_scope FROM %s ORDER BY organization_scope',
                    $tables->quoted('integration_delivery_turns'),
                )),
                'Only unsettled receipts gain a delivery turn, one per exact site and organization.',
            );
        } finally {
            foreach (
                [
                    'integration_delivery_health', 'job_queue_turns', 'integration_delivery_turns',
                    'job_queue_permits', 'integration_inbox', 'resource_site_ownership', 'jobs',
                ] as $name
            ) {
                $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tables->quoted($name)));
            }
        }
    }

    /**
     * Create the legacy tables the upgrade reads, and a permit table an interrupted deployment left behind.
     *
     * @param   Connection  $database  Integration connection.
     * @param   TableNames  $tables    Private table-name compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createLegacyTables(Connection $database, TableNames $tables): void
    {
        $manager = $database->createSchemaManager();
        $jobs = new Table($tables->raw('jobs'));
        $jobs->addColumn('id', Types::GUID);
        $jobs->addColumn('queue', Types::STRING, ['length' => 64]);
        $jobs->addColumn('status', Types::STRING, ['length' => 16]);
        $jobs->addColumn('available_at', Types::DATETIME_IMMUTABLE);
        $jobs->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $manager->createTable($jobs);
        $ownership = new Table($tables->raw('resource_site_ownership'));
        $ownership->addColumn('resource_type', Types::STRING, ['length' => 63]);
        $ownership->addColumn('resource_id', Types::STRING, ['length' => 191]);
        $ownership->addColumn('site_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $ownership->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('resource_type', 'resource_id')->create(),
        );
        $manager->createTable($ownership);
        $inbox = new Table($tables->raw('integration_inbox'));
        $inbox->addColumn('consumer_id', Types::STRING, ['length' => 191]);
        $inbox->addColumn('event_id', Types::GUID);
        $inbox->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $inbox->addColumn('organization_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $inbox->addColumn('status', Types::STRING, ['length' => 16]);
        $inbox->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('consumer_id', 'event_id')->create(),
        );
        $manager->createTable($inbox);
        $permits = new Table($tables->raw('job_queue_permits'));
        $permits->addColumn('queue_id', Types::STRING, ['length' => 64]);
        $permits->addColumn('slot_number', Types::INTEGER);
        $permits->addColumn('runtime_generation', Types::BIGINT);
        $permits->addColumn('maximum_in_flight', Types::INTEGER);
        $permits->addColumn('work_kind', Types::STRING, ['length' => 8, 'notnull' => false]);
        $permits->addColumn('work_id', Types::GUID, ['notnull' => false]);
        $permits->addColumn('consumer_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $permits->addColumn('lease_token', Types::GUID, ['notnull' => false]);
        $permits->addColumn('lease_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $permits->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('queue_id', 'slot_number')->create(),
        );
        $manager->createTable($permits);
    }

    /**
     * Read a two-column result as a key-to-value map with string values.
     *
     * @param   Connection  $database  Integration connection.
     * @param   string      $sql       Two-column selection.
     *
     * @return  array<string, string>  Second column keyed by the first.
     *
     * @since   2.0.0
     */
    private function pairs(Connection $database, string $sql): array
    {
        $pairs = [];
        foreach ($database->fetchAllNumeric($sql) as [$key, $value]) {
            $pairs[(string) $key] = (string) $value;
        }

        return $pairs;
    }

    /**
     * Open the integration connection.
     *
     * @return  Connection  Connection to the configured engine.
     *
     * @since   2.0.0
     */
    private function connection(): Connection
    {
        $database = TestKernelFactory::create(Environment::fromGlobals())->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);

        return $database;
    }
}
