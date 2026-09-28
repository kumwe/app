<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Automation\JobExecutionClass;
use RuntimeException;

/**
 * Gives every declared hot store a drain schedule, an observable run ledger and the indexes its probes need.
 *
 * The retention catalogue declares what each ledger owes; this migration is what makes the declaration
 * operable on an installed database. It creates the one-row-per-store run ledger the drain rate is read
 * from, adds the arrival and settlement indexes that keep every observer probe a bounded index range,
 * and seeds one `system.retention.drain` schedule per store the generic drain serves. Existing rows are
 * left alone on every pass, so an operator's changes to a schedule survive an upgrade.
 *
 * @since  2.0.0
 */
final readonly class RetentionCatalogueMigration implements RepeatableMigration
{
    /**
     * Append-only migration identity reserved for V2-SCL-004 and V2-SCL-008.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260924050000_retention_catalogue';

    /**
     * Stores the generic drain job is scheduled for, with the fixed schedule identity of each.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array SCHEDULES = [
        'outbox_source_events' => '00000000-0000-7000-8000-000000000811',
        'sequenced_journal' => '00000000-0000-7000-8000-000000000812',
        'inbox_receipts' => '00000000-0000-7000-8000-000000000813',
        'job_history' => '00000000-0000-7000-8000-000000000814',
        'process_history' => '00000000-0000-7000-8000-000000000815',
        'export_artifacts' => '00000000-0000-7000-8000-000000000816',
    ];

    /**
     * Indexes the observer probes and drain candidate reads rely on, keyed by table.
     *
     * @var    array<string, list<array{non-empty-list<string>, string}>>
     * @since  2.0.0
     */
    private const array INDEXES = [
        'business_command_idempotency' => [[['created_at'], 'idx_bcommand_idempotency_ingest']],
        'idempotency' => [[['created_at'], 'idx_idempotency_ingest']],
        'business_record_revisions' => [[['created_at'], 'idx_brecord_revision_ingest']],
        'integration_outbox' => [
            [['created_at'], 'idx_integration_outbox_ingest'],
            [['status', 'created_at'], 'idx_integration_outbox_pending_age'],
        ],
        'business_projection_source_events' => [[['recorded_at'], 'idx_projection_source_age']],
        'integration_inbox' => [
            [['status', 'updated_at'], 'idx_integration_inbox_settled'],
            [['first_received_at'], 'idx_integration_inbox_ingest'],
            [['status', 'first_received_at'], 'idx_integration_inbox_pending_age'],
        ],
        'jobs' => [
            [['status', 'completed_at'], 'idx_job_settled'],
            [['status', 'available_at'], 'idx_job_due'],
            [['created_at'], 'idx_job_ingest'],
        ],
        'business_process_work' => [
            [['status', 'updated_at'], 'idx_business_process_work_settled'],
            [['created_at'], 'idx_business_process_work_ingest'],
        ],
        'business_report_export_artifacts' => [[['expires_at'], 'idx_report_exports_expires']],
        'administrator_sessions' => [[['created_at'], 'idx_admin_session_ingest']],
    ];

    /**
     * Resolve all physical names within the installation prefix.
     *
     * @param   TableNames  $tables  Installation table names.
     *
     * @since   2.0.0
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
            throw new RuntimeException('The retention catalogue migration checksum could not be read.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Create the run ledger, add the probe indexes and seed the missing drain schedules.
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
        $before = $manager->introspectSchema();
        $after = clone $before;
        $runs = $this->tables->raw('retention_runs');
        if (!$after->hasTable($runs)) {
            $table = $after->createTable($runs);
            $table->addColumn('store', Types::STRING, ['length' => 64]);
            $table->addColumn('ran_at', Types::DATETIME_IMMUTABLE);
            $table->addColumn('rows_drained', Types::BIGINT);
            $table->addColumn('batches', Types::INTEGER);
            $table->addColumn('elapsed_ms', Types::INTEGER);
            $table->addColumn('final_batch', Types::INTEGER);
            $table->addColumn('backlog_cleared', Types::BOOLEAN);
            $table->addColumn('budget_exhausted', Types::BOOLEAN);
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('store')->create());
        }
        foreach (self::INDEXES as $name => $indexes) {
            $physical = $this->tables->raw($name);
            if (!$after->hasTable($physical)) {
                continue;
            }
            $table = $after->getTable($physical);
            foreach ($indexes as [$columns, $index]) {
                $this->index($table, $columns, $index);
            }
        }
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
        if (!$after->hasTable($this->tables->raw('schedules'))) {
            return;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach (self::SCHEDULES as $store => $id) {
            $existing = $database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE id = ?',
                $this->tables->quoted('schedules'),
            ), [$id]);
            if ($existing !== false) {
                continue;
            }
            $database->insert($this->tables->raw('schedules'), [
                'id' => $id,
                'name' => 'Drain retention store ' . $store,
                'cron_expression' => '* * * * *',
                'timezone' => 'UTC',
                'queue' => 'default',
                'job_type' => RetentionCatalogue::DRAIN_JOB_TYPE,
                'job_schema_version' => 1,
                'payload' => ['store' => $store],
                'priority' => -10,
                'maximum_attempts' => 5,
                'enabled' => true,
                'next_run_at' => $now->modify('+1 minute'),
                'last_run_at' => null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'execution_scope' => JobExecutionClass::Installation->value,
            ], [
                'payload' => Types::JSON,
                'enabled' => Types::BOOLEAN,
                'next_run_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }
    }

    /**
     * Add an index isolated to this installation's prefix unless one of that name already exists.
     *
     * @param   Table                   $table    Table being altered.
     * @param   non-empty-list<string>  $columns  Indexed columns in order.
     * @param   string                  $name     Logical index identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function index(Table $table, array $columns, string $name): void
    {
        $name = ConstraintNameIsolationMigration::isolatedName($table->getObjectName()->toString(), $name);
        if (!$table->hasIndex($name)) {
            $table->addIndex($columns, $name);
        }
    }
}
