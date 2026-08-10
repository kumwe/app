<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Installs transactional events, durable consumer receipts and generic process-manager storage.
 *
 * Every table is created independently and only when absent, making the whole migration safely repeatable
 * after a platform whose DDL commits implicitly interrupts it between table creations.
 *
 * @since  2.0.0
 */
final readonly class BusinessIntegrationSdkMigration implements RepeatableMigration
{
    /** @var string Stable ordered migration identity. @since 2.0.0 */
    public const string ID = '20260810010000_business_integration_sdk';

    /**
     * Bind schema declarations to the installation table prefix.
     *
     * @param   TableNames  $tables  Portable physical table-name compiler.
     *
     * @since   2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /** @return string Stable migration identity. @since 2.0.0 */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind migration-ledger compatibility to the exact published source.
     *
     * @return  string  SHA-256 source-bound checksum.
     *
     * @throws  RuntimeException  When the source cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The business integration migration checksum could not be calculated.');
        }
        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Create every durable integration table in dependency order.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        foreach ($this->tables() as $table) {
            $name = $table->getObjectName()->getUnqualifiedName()->getValue();
            if (!$manager->tablesExist([$name])) {
                $manager->createTable($table);
            }
        }
        $before = $manager->introspectSchema();
        $after = clone $before;
        $schedules = $after->getTable($this->tables->raw('schedules'));
        if (!$schedules->hasColumn('contribution_id')) {
            $schedules->addColumn('contribution_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        }
        if (!$schedules->hasColumn('contribution_checksum')) {
            $schedules->addColumn('contribution_checksum', Types::STRING, ['length' => 64, 'notnull' => false]);
        }
        if (!$schedules->hasColumn('contribution_generation')) {
            $schedules->addColumn('contribution_generation', Types::STRING, ['length' => 191, 'notnull' => false]);
        }
        if (!$schedules->hasColumn('contribution_active')) {
            $schedules->addColumn('contribution_active', Types::BOOLEAN, ['default' => true]);
        }
        if (!$schedules->hasIndex('uniq_schedule_contribution')) {
            $schedules->addUniqueIndex(['contribution_id'], 'uniq_schedule_contribution');
        }
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /** @return list<Table> Tables in foreign-key dependency order. @since 2.0.0 */
    private function tables(): array
    {
        return [
            $this->outbox(),
            $this->consumerCheckpoints(),
            $this->inbox(),
            $this->processes(),
            $this->processWork(),
        ];
    }

    /** @return Table Transactional integration-event outbox. @since 2.0.0 */
    private function outbox(): Table
    {
        $table = new Table($this->tables->raw('integration_outbox'));
        $table->addColumn('event_id', Types::GUID);
        $table->addColumn('event_type', Types::STRING, ['length' => 191]);
        $table->addColumn('schema_version', Types::INTEGER);
        $table->addColumn('sensitivity', Types::STRING, ['length' => 16]);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('organization_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('aggregate_type', Types::STRING, ['length' => 191]);
        $table->addColumn('aggregate_id', Types::STRING, ['length' => 191]);
        $table->addColumn('aggregate_version', Types::BIGINT);
        $table->addColumn('correlation_id', Types::STRING, ['length' => 191]);
        $table->addColumn('envelope', Types::JSON);
        $table->addColumn('status', Types::STRING, ['length' => 16, 'default' => 'pending']);
        $table->addColumn('available_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('attempts', Types::SMALLINT, ['default' => 0]);
        $table->addColumn('maximum_attempts', Types::SMALLINT);
        $this->leaseColumns($table);
        $table->addColumn('failure_classification', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('exception_type', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('error_message', Types::TEXT, ['notnull' => false]);
        $table->addColumn('dispatched_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('retained_until', Types::DATETIME_IMMUTABLE);
        $table->addColumn('replay_count', Types::INTEGER, ['default' => 0]);
        $table->addColumn('replayed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('replayed_by', Types::STRING, ['length' => 191, 'notnull' => false]);
        $this->timestamps($table);
        $table->setPrimaryKey(['event_id']);
        $table->addIndex(['status', 'available_at', 'attempts'], 'idx_integration_outbox_claim');
        $table->addIndex(['aggregate_type', 'aggregate_id', 'aggregate_version'], 'idx_integration_outbox_aggregate');
        $table->addIndex(['correlation_id'], 'idx_integration_outbox_correlation');
        $table->addIndex(['site_identifier', 'organization_id', 'created_at'], 'idx_integration_outbox_scope');
        $table->addIndex(['retained_until', 'status'], 'idx_integration_outbox_retention');
        return $table;
    }

    /** @return Table Per-consumer aggregate ordering checkpoints. @since 2.0.0 */
    private function consumerCheckpoints(): Table
    {
        $table = new Table($this->tables->raw('integration_consumer_checkpoints'));
        $table->addColumn('consumer_id', Types::STRING, ['length' => 191]);
        $table->addColumn('aggregate_type', Types::STRING, ['length' => 191]);
        $table->addColumn('aggregate_id', Types::STRING, ['length' => 191]);
        $table->addColumn('aggregate_version', Types::BIGINT);
        $table->addColumn('event_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['consumer_id', 'aggregate_type', 'aggregate_id']);
        return $table;
    }

    /** @return Table Durable consumer inbox and deduplication ledger. @since 2.0.0 */
    private function inbox(): Table
    {
        $table = new Table($this->tables->raw('integration_inbox'));
        $table->addColumn('consumer_id', Types::STRING, ['length' => 191]);
        $table->addColumn('event_id', Types::GUID);
        $table->addColumn('event_type', Types::STRING, ['length' => 191]);
        $table->addColumn('schema_version', Types::INTEGER);
        $table->addColumn('handler_version', Types::STRING, ['length' => 64]);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('organization_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('aggregate_type', Types::STRING, ['length' => 191]);
        $table->addColumn('aggregate_id', Types::STRING, ['length' => 191]);
        $table->addColumn('aggregate_version', Types::BIGINT);
        $table->addColumn('envelope', Types::JSON);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('attempts', Types::SMALLINT, ['default' => 0]);
        $table->addColumn('maximum_attempts', Types::SMALLINT);
        $table->addColumn('available_at', Types::DATETIME_IMMUTABLE);
        $this->leaseColumns($table);
        $table->addColumn('failure_classification', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('exception_type', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('error_message', Types::TEXT, ['notnull' => false]);
        $table->addColumn('first_received_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['consumer_id', 'event_id']);
        $table->addIndex(['consumer_id', 'status', 'available_at'], 'idx_integration_inbox_claim');
        $table->addIndex(['consumer_id', 'site_identifier', 'organization_id'], 'idx_integration_inbox_scope');
        $table->addIndex(
            ['consumer_id', 'aggregate_type', 'aggregate_id', 'aggregate_version'],
            'idx_integration_inbox_order',
        );
        return $table;
    }

    /** @return Table Optimistic process-manager instances. @since 2.0.0 */
    private function processes(): Table
    {
        $table = new Table($this->tables->raw('business_process_instances'));
        $table->addColumn('process_id', Types::GUID);
        $table->addColumn('process_type', Types::STRING, ['length' => 191]);
        $table->addColumn('correlation_id', Types::STRING, ['length' => 191]);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('organization_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('actor_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('system_identity', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('version', Types::INTEGER);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('state', Types::JSON);
        $table->addColumn('cancellation_by', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('cancellation_note', Types::TEXT, ['notnull' => false]);
        $this->timestamps($table);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('cancelled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['process_id']);
        $table->addUniqueIndex(['process_type', 'correlation_id'], 'uniq_business_process_correlation');
        $table->addIndex(['status', 'updated_at'], 'idx_business_process_status');
        $table->addIndex(['site_identifier', 'organization_id', 'updated_at'], 'idx_business_process_scope');
        return $table;
    }

    /** @return Table Durable process timers, commands and compensation requests. @since 2.0.0 */
    private function processWork(): Table
    {
        $table = new Table($this->tables->raw('business_process_work'));
        $table->addColumn('work_id', Types::GUID);
        $table->addColumn('process_id', Types::GUID);
        $table->addColumn('process_version', Types::INTEGER);
        $table->addColumn('work_kind', Types::STRING, ['length' => 16]);
        $table->addColumn('work_name', Types::STRING, ['length' => 191]);
        $table->addColumn('payload', Types::JSON);
        $table->addColumn('due_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('attempts', Types::SMALLINT, ['default' => 0]);
        $table->addColumn('maximum_attempts', Types::SMALLINT);
        $this->leaseColumns($table);
        $table->addColumn('failure_classification', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('exception_type', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('error_message', Types::TEXT, ['notnull' => false]);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->timestamps($table);
        $table->setPrimaryKey(['work_id']);
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_process_instances'),
            ['process_id'],
            ['process_id'],
            ['onDelete' => 'CASCADE'],
            'fk_business_process_work_process',
        );
        $table->addIndex(['status', 'due_at', 'attempts'], 'idx_business_process_work_claim');
        $table->addIndex(['process_id', 'created_at'], 'idx_business_process_work_process');
        return $table;
    }

    /** @since 2.0.0 */
    private function leaseColumns(Table $table): void
    {
        $table->addColumn('lease_owner', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('lease_token', Types::GUID, ['notnull' => false]);
        $table->addColumn('lease_acquired_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('lease_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('runtime_generation', Types::STRING, ['length' => 191, 'notnull' => false]);
    }

    /** @since 2.0.0 */
    private function timestamps(Table $table): void
    {
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
    }
}
