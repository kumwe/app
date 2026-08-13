<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Automation\JobExecutionClass;
use Kumwe\CMS\Audit\Domain\AuditEnforcementState;
use Kumwe\CMS\Audit\Domain\AuditEventDigest;
use Kumwe\CMS\Audit\Infrastructure\Persistence\AuditAppendOnlyGuard;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;
use Throwable;

/**
 * Turns the audit trail into a tamper-evident store: chained digests, a monotonic position, anchors.
 *
 * Three changes land together because they only mean something together. Every `audit_events` row gains
 * a canonical `digest` of its own fields, a `previous_digest` witness link to the row that was head when
 * it was written, and a database-allocated monotonic `position` — auto-increment on MySQL and MariaDB, an
 * identity column on PostgreSQL, and a recorder-maintained counter on the single-writer SQLite platform
 * used by tests, which has no portable way to attach a generator to an existing column. The new
 * `audit_anchors` table then seals settled position ranges into a chain of its own, which is what makes
 * a deleted or reordered row evident rather than merely unverifiable. Rows written before this migration
 * are digested and chained here in `occurred_at, id` order, so the trail is verifiable from the moment
 * the migration ran; it cannot retroactively prove anything about what happened before it.
 *
 * Append-only enforcement is installed as database triggers rather than left to application discipline.
 * `UPDATE` is refused unconditionally on every driver. `DELETE` is refused unless the session has
 * explicitly opened the retention window through `AuditAppendOnlyGuard`, which is the only sanctioned
 * removal path and archives and anchors the range before it removes anything. The trigger stops every
 * accidental and ad-hoc write; a database account that may drop triggers can still defeat it, which is
 * why `docs/operations/monitoring.md` pairs this with least-privilege account guidance.
 *
 * That last step, and only that step, is allowed to come up short. `CREATE TRIGGER` needs a privilege
 * that managed MySQL services withhold by default — with binary logging enabled and no `SUPER` the
 * server answers 1419 — so insisting on it would make the platform uninstallable on Amazon RDS, Cloud
 * SQL and Azure Database for MySQL. The migration therefore records the refusal as a state and carries
 * on, and everything the rest of this class establishes is unaffected: the chain, the positions and the
 * anchor ledger are what make tampering *evident*, and they need no privilege at all. `audit:verify`
 * reports which of the two postures the server is in, so the weaker one can never be mistaken for the
 * stronger. Any refusal other than the recognised privilege codes still aborts the migration.
 *
 * @since  2.0.0
 */
final readonly class AuditTamperEvidenceMigration implements RepeatableMigration
{
    /**
     * Stable migration identity recorded in the schema ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260813010000_audit_tamper_evidence';

    /**
     * Job type of the scheduled anchor writer seeded by this migration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ANCHOR_JOB_TYPE = 'audit.anchor.record';

    /**
     * Job type of the scheduled chain verification seeded by this migration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string VERIFY_JOB_TYPE = 'audit.trail.verify';

    /**
     * Job type of the scheduled retention pass seeded, disabled, by this migration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string RETENTION_JOB_TYPE = 'audit.retention.enforce';

    /**
     * Capabilities this migration adds to the permission vocabulary, with their operator wording.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array CAPABILITIES = [
        'audit.export' => 'Export the audit trail as a protected, checksummed archive.',
        'audit.manage' => 'Anchor, verify, and apply retention to the tamper-evident audit trail.',
    ];

    /**
     * Rows digested per batch while backfilling the pre-existing trail.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int BATCH_SIZE = 500;

    /**
     * Bind the migration to the prefixed table map.
     *
     * @param  TableNames  $tables  Resolver applying the configured prefix to table names.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Name the identity recorded for this migration in the schema ledger.
     *
     * @return  string  The stable migration identifier.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Derive the ledger checksum from this file's bytes so any edit is detected.
     *
     * @return  string  Stable digest binding the recorded version to this exact implementation.
     *
     * @throws  RuntimeException  When the file digest cannot be calculated.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The audit tamper-evidence migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Apply the chain columns, the anchor ledger, the append-only triggers and the seeded schedules.
     *
     * Every step guards itself, so an interrupted attempt on a platform whose DDL commits implicitly
     * may simply be replayed.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a stored row is malformed or a postcondition does not hold.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $this->addChainColumns($database);
        $this->createAnchorLedger($database);
        $this->backfillChain($database);
        $this->sealPositionColumn($database);
        $enforcement = AuditAppendOnlyGuard::install($database, $this->tables);
        $this->seedCapabilities($database);
        $this->seedSchedules($database);
        $this->assertApplied($database, $enforcement);
    }

    /**
     * Add the nullable digest, witness-link and position columns the backfill then fills in.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function addChainColumns(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $events = $after->getTable($this->tables->raw('audit_events'));
        if (!$events->hasColumn('digest')) {
            $events->addColumn('digest', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        }
        if (!$events->hasColumn('previous_digest')) {
            $events->addColumn('previous_digest', Types::STRING, [
                'length' => 64,
                'fixed' => true,
                'notnull' => false,
            ]);
        }
        if (!$events->hasColumn('position')) {
            $events->addColumn('position', Types::BIGINT, ['notnull' => false]);
        }
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Create the chained anchor ledger that seals settled ranges of the trail.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createAnchorLedger(Connection $database): void
    {
        $name = $this->tables->raw('audit_anchors');
        if ($database->createSchemaManager()->tablesExist([$name])) {
            return;
        }
        $schema = new Schema();
        $table = $schema->createTable($name);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('sequence', Types::BIGINT);
        $table->addColumn('kind', Types::STRING, ['length' => 16]);
        $table->addColumn('from_position', Types::BIGINT);
        $table->addColumn('to_position', Types::BIGINT);
        $table->addColumn('row_count', Types::BIGINT);
        $table->addColumn('rolling_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('previous_digest', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('archive_sha256', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $table->addUniqueIndex(['sequence'], $this->tables->raw('uniq_audit_anchor_sequence'));
        $table->addIndex(['to_position'], $this->tables->raw('idx_audit_anchor_range'));
        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Digest, position and chain every audit row that predates the tamper-evidence layer.
     *
     * Rows are taken in `occurred_at, id` order — the order the trail was already read back in — so the
     * positions the backfill allocates agree with the order the events actually happened in.
     *
     * @param   Connection  $database  Connection the backfill runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a stored row cannot be digested.
     *
     * @since   2.0.0
     */
    private function backfillChain(Connection $database): void
    {
        $table = $this->tables->quoted('audit_events');
        $head = $database->fetchAssociative(sprintf(
            'SELECT position, digest FROM %s WHERE position IS NOT NULL ORDER BY position DESC LIMIT 1',
            $table,
        ));
        $position = 0;
        $previous = null;
        if ($head !== false) {
            $position = $this->number($head['position'] ?? null);
            $previous = is_string($head['digest'] ?? null) ? $head['digest'] : null;
        }
        while (true) {
            $rows = $database->fetchAllAssociative(sprintf(
                'SELECT id, occurred_at, actor_id, action, subject_type, subject_id, outcome, metadata '
                . 'FROM %s WHERE position IS NULL ORDER BY occurred_at ASC, id ASC LIMIT %d',
                $table,
                self::BATCH_SIZE,
            ));
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                $position++;
                $digest = $this->digestOf($row);
                $database->update($this->tables->raw('audit_events'), [
                    'position' => $position,
                    'digest' => $digest,
                    'previous_digest' => $previous,
                ], ['id' => $row['id']]);
                $previous = $digest;
            }
        }
    }

    /**
     * Compute the canonical digest of one pre-existing audit row from its stored fields.
     *
     * @param   array<string, mixed>  $row  Associative row as the driver returned it.
     *
     * @return  string  Lowercase hexadecimal SHA-256 of the canonical event document.
     *
     * @throws  RuntimeException  When the row's identity, instant or metadata is unusable.
     *
     * @since   2.0.0
     */
    private function digestOf(array $row): string
    {
        $id = $row['id'] ?? null;
        $occurredAt = $row['occurred_at'] ?? null;
        if (!is_string($id) || !is_string($occurredAt)) {
            throw new RuntimeException('A stored audit row cannot be digested.');
        }
        $metadata = $row['metadata'] ?? null;
        if (is_resource($metadata)) {
            $metadata = stream_get_contents($metadata);
        }
        $decoded = [];
        if (is_string($metadata) && $metadata !== '') {
            try {
                $decoded = json_decode($metadata, true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new RuntimeException('A stored audit row carries unreadable metadata.', 0, $exception);
            }
        }
        if (!is_array($decoded)) {
            $decoded = [];
        }
        /** @var array<string, mixed> $decoded */
        return AuditEventDigest::compute(
            $id,
            substr($occurredAt, 0, 19),
            $this->optionalText($row['actor_id'] ?? null),
            $this->text($row['action'] ?? null),
            $this->text($row['subject_type'] ?? null),
            $this->optionalText($row['subject_id'] ?? null),
            $this->text($row['outcome'] ?? null),
            $decoded,
        );
    }

    /**
     * Make the position column mandatory, unique and database-allocated for every later insert.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the platform cannot allocate monotonic audit positions.
     *
     * @since   2.0.0
     */
    private function sealPositionColumn(Connection $database): void
    {
        $platform = $database->getDatabasePlatform();
        $manager = $database->createSchemaManager();
        $events = $manager->introspectTableByUnquotedName($this->tables->raw('audit_events'));
        $index = $this->tables->raw('uniq_audit_event_position');
        if (!$events->hasIndex($index)) {
            $database->executeStatement(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (%s)',
                $database->quoteSingleIdentifier($index),
                $this->tables->quoted('audit_events'),
                $database->quoteSingleIdentifier('position'),
            ));
        }
        if ($platform instanceof SQLitePlatform) {
            // SQLite cannot attach a generator to an existing column; the recorder allocates instead.
            return;
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            $extra = $database->fetchOne(
                'SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
                . 'AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$this->tables->raw('audit_events'), 'position'],
            );
            if (is_string($extra) && str_contains(strtolower($extra), 'auto_increment')) {
                return;
            }
            $database->executeStatement(sprintf(
                'ALTER TABLE %s MODIFY %s BIGINT NOT NULL AUTO_INCREMENT',
                $this->tables->quoted('audit_events'),
                $database->quoteSingleIdentifier('position'),
            ));

            return;
        }
        if ($platform instanceof PostgreSQLPlatform) {
            $identity = $database->fetchOne(
                'SELECT is_identity FROM information_schema.columns WHERE table_schema = current_schema() '
                . 'AND table_name = ? AND column_name = ?',
                [$this->tables->raw('audit_events'), 'position'],
            );
            if (in_array($identity, ['YES', 'yes', true], true)) {
                return;
            }
            $next = $database->fetchOne(sprintf(
                'SELECT COALESCE(MAX(position), 0) + 1 FROM %s',
                $this->tables->quoted('audit_events'),
            ));
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s SET NOT NULL',
                $this->tables->quoted('audit_events'),
                $database->quoteSingleIdentifier('position'),
            ));
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s ADD GENERATED BY DEFAULT AS IDENTITY (START WITH %d)',
                $this->tables->quoted('audit_events'),
                $database->quoteSingleIdentifier('position'),
                max(1, $this->number($next)),
            ));

            return;
        }

        throw new RuntimeException('The database platform cannot allocate monotonic audit positions.');
    }

    /**
     * Insert the audit capabilities and grant them to every administrator role.
     *
     * No security epoch is raised for the new grants. The epoch exists to retire authority that a
     * principal must stop holding immediately; effective grants are re-read from the role tables on
     * every session resolve and token verify, so a capability that is only ever *added* reaches its
     * holders on their next request without invalidating anything they already hold.
     *
     * @param   Connection  $database  Connection the seed runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a stored administrator role identity is invalid.
     *
     * @since   2.0.0
     */
    private function seedCapabilities(Connection $database): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $columns = $database->createSchemaManager()
            ->introspectTableByUnquotedName($this->tables->raw('capabilities'));
        foreach (self::CAPABILITIES as $code => $description) {
            $exists = $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$code]);
            if ($exists !== false) {
                continue;
            }
            $values = ['code' => $code, 'description' => $description];
            $types = [];
            if ($columns->hasColumn('owner_kind')) {
                $values['owner_kind'] = 'core';
                $values['owner_identifier'] = 'core';
                $values['allowed_scopes'] = json_encode(['global'], JSON_THROW_ON_ERROR);
                $values['delegable'] = true;
                $values['high_impact'] = true;
                $values['definition_version'] = 1;
                $values['definition_checksum'] = hash('sha256', 'kumwe-core-capability-catalog-v1:' . $code);
                $values['lifecycle_state'] = 'active';
                $types = ['delegable' => Types::BOOLEAN, 'high_impact' => Types::BOOLEAN];
            }
            $database->insert($this->tables->raw('capabilities'), $values, $types);
        }
        $roles = $database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE code = ? ORDER BY id',
            $this->tables->quoted('roles'),
        ), ['administrator']);
        foreach ($roles as $roleId) {
            if (!is_string($roleId) || $roleId === '') {
                throw new RuntimeException('A stored administrator role identity is invalid.');
            }
            foreach (array_keys(self::CAPABILITIES) as $code) {
                $granted = $database->fetchOne(sprintf(
                    'SELECT id FROM %s WHERE role_id = ? AND capability_code = ? AND scope_type = ?',
                    $this->tables->quoted('role_capability_grants'),
                ), [$roleId, $code, 'global']);
                if ($granted !== false) {
                    continue;
                }
                $database->insert($this->tables->raw('role_capability_grants'), [
                    'id' => $this->identifierFor('grant', $roleId . '|' . $code),
                    'role_id' => $roleId,
                    'capability_code' => $code,
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                    'granted_at' => $now,
                    'granted_by' => null,
                ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
            }
        }
    }

    /**
     * Seed the anchor, verification and retention schedules as installation-global work.
     *
     * Retention is seeded disabled with a zero window, so an installation that never configures one
     * keeps its trail unbounded — the deliberate default for evidence.
     *
     * @param   Connection  $database  Connection the seed runs on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function seedSchedules(Connection $database): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $schedules = [
            [
                'id' => '00000000-0000-7000-8000-000000000804',
                'name' => 'Anchor the tamper-evident audit trail',
                'cron' => '17 * * * *',
                'type' => self::ANCHOR_JOB_TYPE,
                'payload' => [],
                'enabled' => true,
                'first' => '+1 hour',
            ],
            [
                'id' => '00000000-0000-7000-8000-000000000805',
                'name' => 'Verify the audit trail digest chain',
                'cron' => '37 3 * * *',
                'type' => self::VERIFY_JOB_TYPE,
                'payload' => ['batch_size' => 1000],
                'enabled' => true,
                'first' => '+1 day',
            ],
            [
                'id' => '00000000-0000-7000-8000-000000000806',
                'name' => 'Apply the audit retention window',
                'cron' => '47 4 * * *',
                'type' => self::RETENTION_JOB_TYPE,
                'payload' => ['retention_days' => 0],
                'enabled' => false,
                'first' => '+1 day',
            ],
        ];
        foreach ($schedules as $schedule) {
            $existing = $database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE job_type = ?',
                $this->tables->quoted('schedules'),
            ), [$schedule['type']]);
            if ($existing === false) {
                $database->insert($this->tables->raw('schedules'), [
                    'id' => $schedule['id'],
                    'name' => $schedule['name'],
                    'cron_expression' => $schedule['cron'],
                    'timezone' => 'UTC',
                    'queue' => 'default',
                    'job_type' => $schedule['type'],
                    'job_schema_version' => 1,
                    'payload' => $schedule['payload'],
                    'priority' => -10,
                    'maximum_attempts' => 5,
                    'enabled' => $schedule['enabled'],
                    'next_run_at' => $now->modify($schedule['first']),
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
            foreach (['jobs', 'schedules'] as $name) {
                $database->executeStatement(sprintf(
                    'UPDATE %s SET execution_scope = ? WHERE job_type = ?',
                    $this->tables->quoted($name),
                ), [JobExecutionClass::Installation->value, $schedule['type']]);
            }
        }
    }

    /**
     * Prove the postconditions this migration exists to establish before it is recorded as applied.
     *
     * The append-only guards are checked conditionally, and the condition is not "were they wanted" but
     * "did this server accept them". When installation reported `Active` the triggers must be observable
     * afterwards, so a silent half-install still fails the migration; when it reported `NotInstalled`
     * the server refused a privilege nobody here can grant, and failing would only turn a documented,
     * reportable degradation into an uninstallable platform. Every other postcondition — the chain
     * columns, the anchor ledger, a fully chained trail, the installation-global schedules — is
     * unconditional, because none of them depends on a privilege.
     *
     * @param   Connection             $database     Connection the checks run on.
     * @param   AuditEnforcementState  $enforcement  What trigger installation reported it achieved.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a column, guard or seeded schedule is missing.
     *
     * @since   2.0.0
     */
    private function assertApplied(Connection $database, AuditEnforcementState $enforcement): void
    {
        $manager = $database->createSchemaManager();
        $events = $manager->introspectTableByUnquotedName($this->tables->raw('audit_events'));
        foreach (['position', 'digest', 'previous_digest'] as $column) {
            if (!$events->hasColumn($column)) {
                throw new RuntimeException('The audit chain columns are missing.');
            }
        }
        if (!$manager->tablesExist([$this->tables->raw('audit_anchors')])) {
            throw new RuntimeException('The audit anchor ledger is missing.');
        }
        $unchained = $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE position IS NULL OR digest IS NULL',
            $this->tables->quoted('audit_events'),
        ));
        if ($this->number($unchained) !== 0) {
            throw new RuntimeException('The audit trail still holds unchained rows.');
        }
        if ($enforcement->installed() && !AuditAppendOnlyGuard::installed($database, $this->tables)) {
            throw new RuntimeException('The audit append-only guards are missing.');
        }
        foreach ([self::ANCHOR_JOB_TYPE, self::VERIFY_JOB_TYPE, self::RETENTION_JOB_TYPE] as $type) {
            $scope = $database->fetchOne(sprintf(
                'SELECT execution_scope FROM %s WHERE job_type = ?',
                $this->tables->quoted('schedules'),
            ), [$type]);
            if ($scope !== JobExecutionClass::Installation->value) {
                throw new RuntimeException('An audit schedule is missing or is not installation-global.');
            }
        }
    }

    /**
     * Derive a stable UUIDv7-shaped identifier for a seeded row from its natural key.
     *
     * A deterministic identifier keeps a replayed migration from inserting a second grant row for the
     * same role and capability on a platform where the guarding read and the insert are not atomic.
     *
     * @param   string  $namespace  Row kind the identifier belongs to, such as `grant`.
     * @param   string  $key        Natural key of the row within that kind.
     *
     * @return  string  Canonical UUID carrying version 7 and variant bits.
     *
     * @since   2.0.0
     */
    private function identifierFor(string $namespace, string $key): string
    {
        $digest = hash('sha256', self::ID . ':' . $namespace . ':' . $key);

        return sprintf(
            '%s-%s-7%s-%x%s-%s',
            substr($digest, 0, 8),
            substr($digest, 8, 4),
            substr($digest, 13, 3),
            8 + (hexdec($digest[16]) % 4),
            substr($digest, 17, 3),
            substr($digest, 20, 12),
        );
    }

    /**
     * Read a non-negative integer a driver may have returned as a string.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  int  The value as a non-negative integer.
     *
     * @throws  RuntimeException  When the value is not a non-negative integer.
     *
     * @since   2.0.0
     */
    private function number(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('An audit migration counter is invalid.');
        }

        return (int) $value;
    }

    /**
     * Read a mandatory text column from a fetched row.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  string  The stored text.
     *
     * @throws  RuntimeException  When the column does not hold text.
     *
     * @since   2.0.0
     */
    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('A stored audit row carries an invalid token.');
        }

        return $value;
    }

    /**
     * Read a nullable text column from a fetched row.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  ?string  The stored text, or null when the column is empty.
     *
     * @throws  RuntimeException  When the column holds something other than text or null.
     *
     * @since   2.0.0
     */
    private function optionalText(mixed $value): ?string
    {
        return $value === null ? null : $this->text($value);
    }
}
