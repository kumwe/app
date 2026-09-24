<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds committed-source staging and bounded record retention capacity without changing shipped migrations.
 *
 * Existing journal sequences remain untouched. Deploy with old writers drained: old binaries append
 * directly to the journal, while this generation stages before assigning checkpoint order. Staging is
 * removed only by an atomic journal transfer; journal history is never age-pruned without an archive.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordScaleMigration implements Migration
{
    /**
     * Append-only identity reserved for record/outbox runtime scale work.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260924020000_business_record_scale';

    /**
     * Bind portable installation-local table names.
     *
     * @param  TableNames  $tables  Physical table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the append-only migration identity.
     *
     * @return  string  Stable migration identifier.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind installed history to these exact migration bytes.
     *
     * @return  string  SHA-256 checksum for the migration ledger.
     *
     * @throws  RuntimeException  When this migration's source cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The business-record scale migration checksum could not be read.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Create staging, covering expiry indexes and upgrade only the untouched shipped retention schedule.
     *
     * At most 100 transactions of 1000 deletions per minute replaces 5000 deletions per hour. This is a
     * capacity ceiling, not a measured rate. Each pass skips live row locks. Operator changes, disabled
     * schedules and replay retention windows are preserved. Legal holds disable the schedule; retained
     * rows and staging must be backed up with the authoritative database and journal head.
     *
     * @param   Connection  $database  Database whose old writer generation has been drained.
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
        $name = $this->tables->raw('business_projection_event_staging');
        if (!$after->hasTable($name)) {
            $staging = $after->createTable($name);
            $staging->addColumn('staging_sequence', Types::BIGINT, ['autoincrement' => true]);
            $staging->addColumn('event_id', Types::GUID);
            $staging->addColumn('event_type', Types::STRING, ['length' => 191]);
            $staging->addColumn('schema_version', Types::INTEGER);
            $staging->addColumn('sensitivity', Types::STRING, ['length' => 16]);
            $staging->addColumn('envelope', Types::JSON);
            $staging->addColumn('event_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
            $staging->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);
            $staging->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('staging_sequence')->create(),
            );
            $staging->addUniqueIndex(['event_id'], ConstraintNameIsolationMigration::isolatedName(
                $name,
                'uniq_projection_staging_event',
            ));
            $this->index($staging, ['recorded_at', 'event_id'], 'idx_projection_staging_age');
            $this->index($staging, ['event_type', 'schema_version', 'staging_sequence'], 'idx_projection_staging_type');
        }
        $idempotency = $this->tables->raw('business_command_idempotency');
        if ($after->hasTable($idempotency)) {
            $this->index($after->getTable($idempotency), ['expires_at', 'id'], 'idx_bcommand_retention_order');
        }
        $outbox = $this->tables->raw('integration_outbox');
        $this->index($after->getTable($outbox), ['retained_until', 'event_id'], 'idx_outbox_retention_order');
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
        if (!$after->hasTable($this->tables->raw('schedules'))) {
            return;
        }
        $schedule = $database->fetchAssociative(sprintf(
            'SELECT id, cron_expression, payload, version FROM %s WHERE id = ? AND job_type = ?',
            $this->tables->quoted('schedules'),
        ), ['00000000-0000-7000-8000-000000000803', 'business.record.idempotency.purge']);
        if ($schedule === false || $schedule['cron_expression'] !== '43 * * * *') {
            return;
        }
        $payload = $schedule['payload'];
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        }
        if (
            !is_array($payload) || count($payload) !== 2
            || ($payload['batch_size'] ?? null) !== 500 || ($payload['maximum_batches'] ?? null) !== 10
        ) {
            return;
        }
        $version = $schedule['version'];
        if (!is_int($version) && (!is_string($version) || preg_match('/^[1-9][0-9]*$/D', $version) !== 1)) {
            throw new RuntimeException('The retention schedule version is invalid.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $database->update($this->tables->raw('schedules'), [
            'cron_expression' => '* * * * *',
            'payload' => ['batch_size' => 1000, 'maximum_batches' => 100],
            'next_run_at' => $now->modify('+1 minute'),
            'updated_at' => $now,
            'version' => (int) $version + 1,
        ], ['id' => $schedule['id'], 'version' => $schedule['version']], [
            'payload' => Types::JSON,
            'next_run_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Add a prefix-isolated covering index once.
     *
     * @param   Table         $table    Declared physical table.
     * @param   non-empty-list<string>  $columns  Ordered index columns.
     * @param   string        $name     Logical index identifier.
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
