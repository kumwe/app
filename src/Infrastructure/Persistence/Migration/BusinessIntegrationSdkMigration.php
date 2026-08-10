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
    /**
     * Stable ordered migration identity.
     *
     * @var    string
     * @since  2.0.0
     */
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

    /**
     * Return the stable ordered migration identity.
     *
     * @return  string  Stable migration identity.
     *
     * @since   2.0.0
     */
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
        if ((int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE singleton_id = 1',
            $this->tables->quoted('business_projection_event_head'),
        )) === 0) {
            $database->insert($this->tables->raw('business_projection_event_head'), [
                'singleton_id' => 1,
                'last_sequence' => 0,
            ], [
                'singleton_id' => Types::SMALLINT,
                'last_sequence' => Types::BIGINT,
            ]);
        }
        $before = $manager->introspectSchema();
        $after = clone $before;
        $scheduleTable = $this->tables->raw('schedules');
        if ($after->hasTable($scheduleTable)) {
            $schedules = $after->getTable($scheduleTable);
            if (!$schedules->hasColumn('contribution_id')) {
                $schedules->addColumn('contribution_id', Types::STRING, ['length' => 191, 'notnull' => false]);
            }
            if (!$schedules->hasColumn('contribution_checksum')) {
                $schedules->addColumn('contribution_checksum', Types::STRING, ['length' => 64, 'notnull' => false]);
            }
            if (!$schedules->hasColumn('contribution_generation')) {
                $schedules->addColumn(
                    'contribution_generation',
                    Types::STRING,
                    ['length' => 191, 'notnull' => false],
                );
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
    }

    /**
     * Build every integration and projection table in foreign-key dependency order.
     *
     * @return  list<Table>  Portable table declarations in creation order.
     *
     * @since   2.0.0
     */
    private function tables(): array
    {
        return [
            $this->jobQueueRuntime(),
            $this->outbox(),
            $this->consumerCheckpoints(),
            $this->inbox(),
            $this->processes(),
            $this->processWork(),
            $this->projectionEventHead(),
            $this->projectionSourceEvents(),
            $this->projectionGenerations(),
            $this->projectionRows(),
            $this->reportExportArtifacts(),
        ];
    }

    /**
     * Declare the per-queue row that serializes cross-process contributed queue claims.
     *
     * Policy values are copied from the active trusted runtime whenever a worker claims. The row is a
     * lock and an operator checkpoint rather than an authority source: enforcement always resolves the
     * current in-memory signed declaration before taking this durable lock.
     *
     * @return  Table  Durable queue policy claim lock and observation row.
     *
     * @since   2.0.0
     */
    private function jobQueueRuntime(): Table
    {
        $table = new Table($this->tables->raw('job_queue_runtime'));
        $table->addColumn('queue_id', Types::STRING, ['length' => 64]);
        $table->addColumn('lease_seconds', Types::SMALLINT);
        $table->addColumn('maximum_attempts', Types::SMALLINT);
        $table->addColumn('maximum_in_flight', Types::INTEGER);
        $table->addColumn('retention_days', Types::INTEGER);
        $table->addColumn('runtime_generation', Types::BIGINT);
        $table->addColumn('last_claimed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['queue_id']);

        return $table;
    }

    /**
     * Declare the transactional integration-event outbox.
     *
     * @return  Table  Portable outbox table declaration.
     *
     * @since   2.0.0
     */
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

    /**
     * Declare per-consumer aggregate ordering checkpoints.
     *
     * @return  Table  Portable consumer checkpoint table declaration.
     *
     * @since   2.0.0
     */
    private function consumerCheckpoints(): Table
    {
        $table = new Table($this->tables->raw('integration_consumer_checkpoints'));
        $table->addColumn('consumer_id', Types::STRING, ['length' => 191]);
        $table->addColumn('scope_checksum', Types::STRING, ['length' => 64]);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('organization_scope', Types::STRING, ['length' => 191]);
        $table->addColumn('aggregate_type', Types::STRING, ['length' => 191]);
        $table->addColumn('aggregate_id', Types::STRING, ['length' => 191]);
        $table->addColumn('aggregate_version', Types::BIGINT);
        $table->addColumn('event_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['consumer_id', 'scope_checksum', 'aggregate_type', 'aggregate_id']);
        $table->addIndex(['site_identifier', 'organization_scope'], 'idx_integration_checkpoint_scope');
        return $table;
    }

    /**
     * Declare the durable consumer inbox and deduplication ledger.
     *
     * @return  Table  Portable inbox table declaration.
     *
     * @since   2.0.0
     */
    private function inbox(): Table
    {
        $table = new Table($this->tables->raw('integration_inbox'));
        $table->addColumn('consumer_id', Types::STRING, ['length' => 191]);
        $table->addColumn('event_id', Types::GUID);
        $table->addColumn('queue', Types::STRING, ['length' => 64]);
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
        $table->addColumn('evidence_compacted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['consumer_id', 'event_id']);
        $table->addIndex(['consumer_id', 'status', 'available_at'], 'idx_integration_inbox_claim');
        $table->addIndex(['queue', 'status', 'lease_expires_at'], 'idx_integration_inbox_queue');
        $table->addIndex(['consumer_id', 'site_identifier', 'organization_id'], 'idx_integration_inbox_scope');
        $table->addIndex(
            ['consumer_id', 'aggregate_type', 'aggregate_id', 'aggregate_version'],
            'idx_integration_inbox_order',
        );
        return $table;
    }

    /**
     * Declare optimistic process-manager instances.
     *
     * @return  Table  Portable process-instance table declaration.
     *
     * @since   2.0.0
     */
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
        $table->addUniqueIndex(
            ['process_type', 'site_identifier', 'correlation_id'],
            'uniq_business_process_correlation',
        );
        $table->addIndex(['status', 'updated_at'], 'idx_business_process_status');
        $table->addIndex(['site_identifier', 'organization_id', 'updated_at'], 'idx_business_process_scope');
        return $table;
    }

    /**
     * Declare durable process timers, commands and compensation requests.
     *
     * @return  Table  Portable process-work table declaration.
     *
     * @since   2.0.0
     */
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

    /**
     * Declare the serialization fence shared by source appends and generation swaps.
     *
     * @return  Table  Portable singleton journal-head table declaration.
     *
     * @since   2.0.0
     */
    private function projectionEventHead(): Table
    {
        $table = new Table($this->tables->raw('business_projection_event_head'));
        $table->addColumn('singleton_id', Types::SMALLINT);
        $table->addColumn('last_sequence', Types::BIGINT, ['default' => 0]);
        $table->setPrimaryKey(['singleton_id']);

        return $table;
    }

    /**
     * Declare the immutable authoritative projection source journal.
     *
     * @return  Table  Portable projection source-event table declaration.
     *
     * @since   2.0.0
     */
    private function projectionSourceEvents(): Table
    {
        $table = new Table($this->tables->raw('business_projection_source_events'));
        $table->addColumn('source_sequence', Types::BIGINT, ['autoincrement' => true]);
        $table->addColumn('event_id', Types::GUID);
        $table->addColumn('event_type', Types::STRING, ['length' => 191]);
        $table->addColumn('schema_version', Types::INTEGER);
        $table->addColumn('sensitivity', Types::STRING, ['length' => 16]);
        $table->addColumn('envelope', Types::JSON);
        $table->addColumn('event_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['source_sequence']);
        $table->addUniqueIndex(['event_id'], 'uniq_projection_source_event');
        $table->addIndex(
            ['event_type', 'schema_version', 'source_sequence'],
            'idx_projection_source_contract',
        );

        return $table;
    }

    /**
     * Declare replaceable projection generations and reproducibility evidence.
     *
     * @return  Table  Portable projection-generation table declaration.
     *
     * @since   2.0.0
     */
    private function projectionGenerations(): Table
    {
        $table = new Table($this->tables->raw('business_projection_generations'));
        $table->addColumn('generation_id', Types::GUID);
        $table->addColumn('projection_id', Types::STRING, ['length' => 191]);
        $table->addColumn('definition_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('handler_version', Types::STRING, ['length' => 64]);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('last_sequence', Types::BIGINT, ['default' => 0]);
        $table->addColumn('source_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('projection_checksum', Types::STRING, [
            'length' => 64,
            'fixed' => true,
            'notnull' => false,
        ]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('activated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('superseded_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['generation_id']);
        $table->addIndex(['projection_id', 'status'], 'idx_projection_generation_active');
        $table->addIndex(['projection_id', 'created_at'], 'idx_projection_generation_history');

        return $table;
    }

    /**
     * Declare typed JSON rows isolated by replaceable generation.
     *
     * @return  Table  Portable projection-row table declaration.
     *
     * @since   2.0.0
     */
    private function projectionRows(): Table
    {
        $table = new Table($this->tables->raw('business_projection_rows'));
        $table->addColumn('generation_id', Types::GUID);
        $table->addColumn('projection_id', Types::STRING, ['length' => 191]);
        $table->addColumn('row_key_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('row_key', Types::JSON);
        $table->addColumn('row_values', Types::JSON);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['generation_id', 'row_key_checksum']);
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_projection_generations'),
            ['generation_id'],
            ['generation_id'],
            ['onDelete' => 'CASCADE'],
            'fk_projection_rows_generation',
        );
        $table->addIndex(['projection_id', 'generation_id'], 'idx_projection_rows_generation');

        return $table;
    }

    /**
     * Declare append-only report-export metadata versions in the authoritative database.
     *
     * @return  Table  Portable export metadata ledger declaration.
     *
     * @since   2.0.0
     */
    private function reportExportArtifacts(): Table
    {
        $table = new Table($this->tables->raw('business_report_export_artifacts'));
        $table->addColumn('artifact_id', Types::GUID);
        $table->addColumn('version', Types::SMALLINT);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('actor_id', Types::STRING, ['length' => 191]);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('document', Types::TEXT);
        $table->addColumn('document_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->setPrimaryKey(['artifact_id', 'version']);
        $table->addIndex(['status', 'expires_at'], 'idx_report_exports_expiry');
        $table->addIndex(
            ['site_identifier', 'actor_id', 'expires_at'],
            'idx_report_exports_scope',
        );

        return $table;
    }

    /**
     * Add the common fenced lease columns to a durable work table.
     *
     * @param   Table  $table  Mutable portable table declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function leaseColumns(Table $table): void
    {
        $table->addColumn('lease_owner', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('lease_token', Types::GUID, ['notnull' => false]);
        $table->addColumn('lease_acquired_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('lease_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('runtime_generation', Types::STRING, ['length' => 191, 'notnull' => false]);
    }

    /**
     * Add common creation and update timestamps to a durable table.
     *
     * @param   Table  $table  Mutable portable table declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function timestamps(Table $table): void
    {
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
    }
}
