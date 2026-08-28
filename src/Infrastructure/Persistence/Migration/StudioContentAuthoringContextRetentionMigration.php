<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Automation\Job\PurgeStudioContentAuthoringContextsHandler;
use Kumwe\App\Application\Automation\JobExecutionClass;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Seeds installation-wide retention for opaque Studio Content authoring contexts.
 *
 * The context table is shared by every site and its rows carry a hard expiry. This separate append-only
 * migration preserves the checksum of the table-creation migration while ensuring expired bindings are
 * eventually removed even when an operator never creates a maintenance schedule manually.
 *
 * @since  2.0.0
 */
final readonly class StudioContentAuthoringContextRetentionMigration implements RepeatableMigration
{
    /**
     * Stable append-only migration identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260827020000_studio_content_authoring_context_retention';

    /**
     * Fixed seed identity outside the previously shipped core schedule range.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string SCHEDULE_ID = '00000000-0000-7000-8000-000000000807';

    /**
     * Bind schedule persistence to the installation's prefix-aware table names.
     *
     * @param   TableNames  $tables  Physical table-name compiler.
     *
     * @since   2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the immutable schema-ledger identity.
     *
     * @return  string  Stable migration version.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind applied history to these exact retention and schedule bytes.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When the source digest cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The Studio Content authoring context retention checksum is unavailable.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Seed one global schedule and repair persisted execution-scope and ownership classifications.
     *
     * An operator-created schedule for the same job type wins: the migration does not overwrite its
     * cadence or payload, but it still repairs that schedule and any queued work to installation scope.
     * Installation-global automation has no site owner, so every repaired resource loses only its matching
     * `schedule` or `job` ownership row in the same migration pass.
     *
     * @param   Connection  $database  Installation database holding schedules and queued jobs.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When schedule persistence or verification fails.
     * @throws  RuntimeException  When the required global schedule postcondition cannot be established.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $existing = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE job_type = ?',
            $this->tables->quoted('schedules'),
        ), [PurgeStudioContentAuthoringContextsHandler::JOB_TYPE]);
        if ($existing === false) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $database->insert($this->tables->raw('schedules'), [
                'id' => self::SCHEDULE_ID,
                'name' => 'Purge expired Studio Content authoring contexts',
                'cron_expression' => '11 * * * *',
                'timezone' => 'UTC',
                'queue' => 'default',
                'job_type' => PurgeStudioContentAuthoringContextsHandler::JOB_TYPE,
                'job_schema_version' => 1,
                'payload' => ['batch_size' => 1_000, 'maximum_batches' => 10],
                'priority' => -10,
                'maximum_attempts' => 5,
                'enabled' => true,
                'next_run_at' => $now->modify('+1 hour'),
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
        foreach (['job' => 'jobs', 'schedule' => 'schedules'] as $resourceType => $table) {
            $database->executeStatement(sprintf(
                'UPDATE %s SET execution_scope = ? WHERE job_type = ?',
                $this->tables->quoted($table),
            ), [JobExecutionClass::Installation->value, PurgeStudioContentAuthoringContextsHandler::JOB_TYPE]);
            // Resolve the retention resource identifiers first and compare them as bound
            // parameters. An IN (SELECT ...) across resource_site_ownership and the
            // automation tables mixes per-table string collations, which MariaDB and
            // MySQL refuse on installations whose server default collation differs
            // from the ownership table's declared one (SQLSTATE HY000/1267); a bound
            // parameter always adopts the compared column's collation.
            $resourceIds = self::resourceIds($database->fetchFirstColumn(sprintf(
                'SELECT id FROM %s WHERE job_type = ?',
                $this->tables->quoted($table),
            ), [PurgeStudioContentAuthoringContextsHandler::JOB_TYPE]));
            if ($resourceIds !== []) {
                $database->executeStatement(sprintf(
                    'DELETE FROM %s WHERE resource_type = ? AND resource_id IN (%s)',
                    $this->tables->quoted('resource_site_ownership'),
                    implode(', ', array_fill(0, count($resourceIds), '?')),
                ), [$resourceType, ...$resourceIds]);
            }
            $invalidScopes = $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE job_type = ? '
                . 'AND (execution_scope IS NULL OR execution_scope <> ?)',
                $this->tables->quoted($table),
            ), [
                PurgeStudioContentAuthoringContextsHandler::JOB_TYPE,
                JobExecutionClass::Installation->value,
            ]);
            if (self::rowCount($invalidScopes) !== 0) {
                throw new RuntimeException(sprintf(
                    'A Studio Content authoring context retention %s is not installation-global.',
                    $resourceType,
                ));
            }
            $owned = $resourceIds === [] ? 0 : $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE resource_type = ? AND resource_id IN (%s)',
                $this->tables->quoted('resource_site_ownership'),
                implode(', ', array_fill(0, count($resourceIds), '?')),
            ), [$resourceType, ...$resourceIds]);
            if (self::rowCount($owned) !== 0) {
                throw new RuntimeException(sprintf(
                    'A Studio Content authoring context retention %s still has site ownership.',
                    $resourceType,
                ));
            }
        }
        $schedules = $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE job_type = ?',
            $this->tables->quoted('schedules'),
        ), [PurgeStudioContentAuthoringContextsHandler::JOB_TYPE]);
        if (self::rowCount($schedules) === 0) {
            throw new RuntimeException(
                'The Studio Content authoring context retention schedule is missing.',
            );
        }
    }

    /**
     * Normalize portable DBAL count results without accepting malformed persistence values.
     *
     * @param   mixed  $value  Driver result returned for a `COUNT(*)` expression.
     *
     * @return  int  Non-negative row count.
     *
     * @throws  RuntimeException  When the driver result is not a non-negative decimal integer.
     *
     * @since   2.0.0
     */
    /**
     * Normalize fetched automation resource identifiers to the string form the
     * ownership registry stores, refusing a malformed identifier outright.
     *
     * @param   list<mixed>  $values  Identifier column values for one automation table.
     *
     * @return  list<string>  Bound-parameter-ready resource identifiers.
     *
     * @throws  RuntimeException  When an identifier is neither a non-empty string nor an integer.
     *
     * @since   2.0.0
     */
    private static function resourceIds(array $values): array
    {
        $identifiers = [];
        foreach ($values as $value) {
            if (is_int($value)) {
                $identifiers[] = (string) $value;
                continue;
            }
            if (!is_string($value) || $value === '') {
                throw new RuntimeException(
                    'A Studio Content authoring context retention resource identifier is invalid.'
                );
            }
            $identifiers[] = $value;
        }

        return $identifiers;
    }

    private static function rowCount(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new RuntimeException('A Studio Content authoring context retention count is invalid.');
        }

        return (int) $value;
    }
}
