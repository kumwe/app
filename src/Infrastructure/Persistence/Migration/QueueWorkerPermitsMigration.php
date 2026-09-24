<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueueFairness;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds bounded shared queue permits and durable scheduling turns for independent inbox workers.
 *
 * @since  2.0.0
 */
final readonly class QueueWorkerPermitsMigration implements RepeatableMigration
{
    /**
     * Append-only worker migration identity reserved for V2-SCL-006 and V2-SCL-007.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260924030000_queue_worker_permits';

    /**
     * Resolve all physical names within the installation prefix.
     *
     * @param  TableNames  $tables  Installation table names.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the migration ledger identity.
     *
     * @return  string  Ordered identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Freeze the migration's source bytes when shipped.
     *
     * @return  string  Source-bound checksum.
     *
     * @throws  RuntimeException  When the source is unreadable.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The queue permit migration checksum could not be read.');
        }
        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Create each independent table repeatably after an interrupted DDL deployment.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $permits = new Table($this->tables->raw('job_queue_permits'));
        $permits->addColumn('queue_id', Types::STRING, ['length' => 64]);
        $permits->addColumn('slot_number', Types::INTEGER);
        $permits->addColumn('runtime_generation', Types::BIGINT);
        $permits->addColumn('maximum_in_flight', Types::INTEGER);
        $permits->addColumn('work_kind', Types::STRING, ['length' => 8, 'notnull' => false]);
        $permits->addColumn('work_id', Types::GUID, ['notnull' => false]);
        $permits->addColumn('consumer_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $permits->addColumn('lease_token', Types::GUID, ['notnull' => false]);
        $permits->addColumn('lease_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $permits->addColumn('last_claimed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $permits->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()
            ->setUnquotedColumnNames('queue_id', 'slot_number')->create());
        $permits->addIndex(
            ['queue_id', 'lease_token'],
            'idx_queue_lease_' . substr(hash('sha256', $this->tables->raw('job_queue_permits')), 0, 16)
        );
        $turns = new Table($this->tables->raw('integration_delivery_turns'));
        $turns->addColumn('consumer_id', Types::STRING, ['length' => 191]);
        $turns->addColumn('scope_checksum', Types::STRING, ['length' => 64]);
        $turns->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $turns->addColumn('organization_scope', Types::STRING, ['length' => 191]);
        $turns->addColumn('last_claimed_at', Types::DATETIME_IMMUTABLE);
        $turns->addColumn('claim_count', Types::BIGINT, ['default' => 0]);
        $turns->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()
            ->setUnquotedColumnNames('consumer_id', 'scope_checksum')->create());
        $jobs = new Table($this->tables->raw('job_queue_turns'));
        $jobs->addColumn('queue_id', Types::STRING, ['length' => 64]);
        $jobs->addColumn('scope_key', Types::STRING, ['length' => 64]);
        $jobs->addColumn('last_claimed_at', Types::DATETIME_IMMUTABLE);
        $jobs->addColumn('claim_count', Types::BIGINT, ['default' => 0]);
        $jobs->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()
            ->setUnquotedColumnNames('queue_id', 'scope_key')->create());
        $health = new Table($this->tables->raw('integration_delivery_health'));
        $health->addColumn('consumer_id', Types::STRING, ['length' => 191]);
        $health->addColumn('handler_version', Types::STRING, ['length' => 64]);
        $health->addColumn('lease_token', Types::GUID, ['notnull' => false]);
        $health->addColumn('lease_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $health->addColumn('blocked_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $health->addColumn('failure_streak', Types::INTEGER, ['default' => 0]);
        $health->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()
            ->setUnquotedColumnNames('consumer_id')->create());
        foreach ([$permits, $turns, $jobs, $health] as $table) {
            if (!$database->createSchemaManager()->tablesExist([$table->getObjectName()->toString()])) {
                foreach ($database->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
                    $database->executeStatement($sql);
                }
            }
        }
        $manager = $database->createSchemaManager();
        // Preserve repeatability when a deployment stopped after creating the permit table.
        $before = $manager->introspectTableByUnquotedName($this->tables->raw('job_queue_permits'));
        $after = clone $before;
        if (!$after->hasColumn('last_claimed_at')) {
            $after->addColumn('last_claimed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        }
        $leaseIndex = 'idx_queue_lease_' . substr(hash('sha256', $this->tables->raw('job_queue_permits')), 0, 16);
        if (!$after->hasIndex($leaseIndex)) {
            $after->addIndex(['queue_id', 'lease_token'], $leaseIndex);
        }
        $difference = $manager->createComparator()->compareTables($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterTableSQL($difference) as $sql) {
            $database->executeStatement($sql);
        }
        $before = $manager->introspectTableByUnquotedName($this->tables->raw('jobs'));
        $after = clone $before;
        if (!$after->hasColumn('worker_scope')) {
            $after->addColumn('worker_scope', Types::STRING, ['length' => 64, 'notnull' => false]);
        }
        $turnIndex = 'idx_job_turn_' . substr(hash('sha256', $this->tables->raw('jobs')), 0, 16);
        if (!$after->hasIndex($turnIndex)) {
            $after->addIndex(['queue', 'worker_scope', 'status', 'available_at'], $turnIndex);
        }
        $difference = $manager->createComparator()->compareTables($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterTableSQL($difference) as $sql) {
            $database->executeStatement($sql);
        }
        // Resume bounded backfill after a crash. The old ownership table remains the authority source.
        $fairness = new DoctrineJobQueueFairness($database, $this->tables);
        $identity = $database->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'CAST(j.id AS VARCHAR)' : 'j.id';
        while (
            $database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE worker_scope IS NULL LIMIT 1',
                $this->tables->quoted('jobs'),
            )) !== false
        ) {
            $rows = $database->fetchAllAssociative(sprintf(
                'SELECT j.id, j.queue, o.site_identifier FROM %s j LEFT JOIN %s o '
                . "ON o.resource_type = 'job' AND o.resource_id = %s WHERE j.worker_scope IS NULL LIMIT 500",
                $this->tables->quoted('jobs'),
                $this->tables->quoted('resource_site_ownership'),
                $identity,
            ));
            foreach ($rows as $row) {
                if (
                    !is_string($row['queue']) || !is_string($row['id'])
                    || ($row['site_identifier'] !== null && !is_string($row['site_identifier']))
                ) {
                    throw new RuntimeException('A legacy job has malformed scheduling ownership.');
                }
                $fairness->record($row['queue'], $row['id'], $row['site_identifier'], null);
            }
        }

        do {
            $rows = $database->fetchAllAssociative(sprintf(
                'SELECT DISTINCT i.consumer_id, i.site_identifier, i.organization_id FROM %s i '
                . "WHERE i.status <> 'completed' AND NOT EXISTS (SELECT 1 FROM %s t "
                . 'WHERE t.consumer_id = i.consumer_id AND t.site_identifier = i.site_identifier '
                . "AND t.organization_scope = COALESCE(i.organization_id, '')) LIMIT 500",
                $this->tables->quoted('integration_inbox'),
                $this->tables->quoted('integration_delivery_turns'),
            ));
            foreach ($rows as $row) {
                $site = $row['site_identifier'];
                $organization = $row['organization_id'] ?? '';
                if (!is_string($site) || !is_string($organization)) {
                    throw new RuntimeException('A legacy inbox receipt has malformed scheduling scope.');
                }
                $database->insert($this->tables->raw('integration_delivery_turns'), [
                    'consumer_id' => $row['consumer_id'],
                    'scope_checksum' => hash('sha256', $site . "\0" . $organization),
                    'site_identifier' => $site, 'organization_scope' => $organization,
                    'last_claimed_at' => new \DateTimeImmutable('1970-01-01T00:00:00+00:00'), 'claim_count' => 0,
                ], ['last_claimed_at' => Types::DATETIME_IMMUTABLE]);
            }
        } while ($rows !== []);
    }
}
