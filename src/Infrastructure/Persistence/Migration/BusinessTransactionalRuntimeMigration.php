<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Installs the durable control plane for definition-compiled business schemas.
 *
 * Generated business tables are deliberately absent from this migration. They are
 * created only by the approved, checksummed BusinessSchemaExecutor.
 */
final readonly class BusinessTransactionalRuntimeMigration implements RepeatableMigration
{
    public const string ID = '20260808010000_business_transactional_runtime';

    /** @var array<string, string> */
    private const array CAPABILITIES = [
        'business.schema.read' => 'Inspect business schema plans, installations, and execution outcomes.',
        'business.schema.plan' => 'Create deterministic business schema plans.',
        'business.schema.approve' => 'Approve an unchanged business schema plan.',
        'business.schema.execute' => 'Execute an approved business schema plan.',
        'business.schema.recover' => 'Resume or reconcile interrupted business schema execution.',
        'business.schema.destructive' => 'Authorize destructive business schema work with recovery evidence.',
        'business.record.create' => 'Create typed business records.',
        'business.record.read' => 'Read an individual typed business record.',
        'business.record.browse' => 'Browse bounded typed business record projections.',
        'business.record.update' => 'Update typed business records with optimistic concurrency.',
        'business.record.archive' => 'Archive typed business records.',
        'business.record.delete' => 'Delete typed business records when their definition permits it.',
        'business.record.restore' => 'Restore archived typed business records.',
        'business.record.action' => 'Execute declared business record actions and workflow transitions.',
        'business.record.relate' => 'Mutate declared business relationships and ordered lines.',
        'business.record.history' => 'Read immutable business record revision history.',
    ];

    public function __construct(private TableNames $tables)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The business transactional runtime checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        foreach ($this->controlTables() as $table) {
            $name = $table->getObjectName()->getUnqualifiedName()->getValue();
            if (!$manager->tablesExist([$name])) {
                $manager->createTable($table);
            }
        }

        $this->ensureFence($database);
        $this->ensureCapabilities($database);
        $this->reconcileOrderedLinesFieldType($database);
    }

    /** @return list<Table> */
    private function controlTables(): array
    {
        return [
            $this->recoveryEvidence(),
            $this->installations(),
            $this->plans(),
            $this->steps(),
            $this->fence(),
            $this->recordRevisions(),
            $this->commandIdempotency(),
        ];
    }

    private function recoveryEvidence(): Table
    {
        $table = new Table($this->tables->raw('business_schema_recovery_evidence'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('database_driver', Types::STRING, ['length' => 16]);
        $table->addColumn('database_server_version', Types::STRING, ['length' => 191]);
        $table->addColumn('application_release', Types::STRING, ['length' => 191]);
        $table->addColumn('source_schema_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('backup_manifest_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('restore_tested', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('backup_created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('verified_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('verified_by', Types::STRING, ['length' => 191]);
        $table->addColumn('drill_reference', Types::STRING, ['length' => 191]);
        $table->addColumn('details', Types::JSON);
        $this->primary($table, 'id');
        $table->addIndex(
            ['site_identifier', 'source_schema_checksum', 'verified_at'],
            'idx_bschema_recovery_source',
        );

        return $table;
    }

    private function installations(): Table
    {
        $table = new Table($this->tables->raw('business_schema_installations'));
        $table->addColumn('definition_id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('owner_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('definition_version', Types::INTEGER);
        $table->addColumn('definition_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('schema_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('blueprint', Types::JSON);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('installed_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'definition_id');
        $table->addIndex(['site_identifier', 'status'], 'idx_bschema_install_site');
        $table->addIndex(['owner_identifier', 'status'], 'idx_bschema_install_owner');
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_definitions'),
            ['definition_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_bschema_install_definition',
        );

        return $table;
    }

    private function plans(): Table
    {
        $table = new Table($this->tables->raw('business_schema_plans'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('definition_id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('from_definition_version', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('to_definition_version', Types::INTEGER);
        $table->addColumn('from_definition_checksum', Types::STRING, [
            'length' => 64, 'fixed' => true, 'notnull' => false,
        ]);
        $table->addColumn('to_definition_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('from_schema_checksum', Types::STRING, [
            'length' => 64, 'fixed' => true, 'notnull' => false,
        ]);
        $table->addColumn('target_schema_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('plan_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('risk', Types::STRING, ['length' => 32]);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $table->addColumn('canonical_plan', Types::JSON);
        $table->addColumn('created_by', Types::STRING, ['length' => 191]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('approved_by', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('approved_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('approval_checksum', Types::STRING, [
            'length' => 64, 'fixed' => true, 'notnull' => false,
        ]);
        $table->addColumn('confirmation_digest', Types::STRING, [
            'length' => 64, 'fixed' => true, 'notnull' => false,
        ]);
        $table->addColumn('recovery_evidence_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('execution_fence', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('outcome', Types::JSON, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'id');
        $table->addUniqueIndex(['plan_checksum'], 'uniq_bschema_plan_checksum');
        $table->addIndex(['definition_id', 'status', 'created_at'], 'idx_bschema_plan_definition');
        $table->addIndex(['site_identifier', 'status'], 'idx_bschema_plan_site');
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_definitions'),
            ['definition_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_bschema_plan_definition',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_schema_recovery_evidence'),
            ['recovery_evidence_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_bschema_plan_recovery',
        );

        return $table;
    }

    private function steps(): Table
    {
        $table = new Table($this->tables->raw('business_schema_plan_steps'));
        $table->addColumn('plan_id', Types::GUID);
        $table->addColumn('ordinal', Types::INTEGER);
        $table->addColumn('operation_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('operation_kind', Types::STRING, ['length' => 48]);
        $table->addColumn('risk', Types::STRING, ['length' => 32]);
        $table->addColumn('state', Types::STRING, ['length' => 24]);
        $table->addColumn('attempt', Types::INTEGER, ['default' => 0]);
        $table->addColumn('execution_fence', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('chunk_cursor', Types::JSON, ['notnull' => false]);
        $table->addColumn('before_schema_checksum', Types::STRING, [
            'length' => 64, 'fixed' => true, 'notnull' => false,
        ]);
        $table->addColumn('after_schema_checksum', Types::STRING, [
            'length' => 64, 'fixed' => true, 'notnull' => false,
        ]);
        $table->addColumn('outcome', Types::JSON, ['notnull' => false]);
        $table->addColumn('error_code', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'plan_id', 'ordinal');
        $table->addIndex(['plan_id', 'state'], 'idx_bschema_step_state');
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_schema_plans'),
            ['plan_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_bschema_step_plan',
        );

        return $table;
    }

    private function fence(): Table
    {
        $table = new Table($this->tables->raw('business_schema_fence'));
        $table->addColumn('singleton_key', Types::SMALLINT);
        $table->addColumn('fence', Types::BIGINT);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'singleton_key');

        return $table;
    }

    private function recordRevisions(): Table
    {
        $table = new Table($this->tables->raw('business_record_revisions'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('definition_id', Types::GUID);
        $table->addColumn('definition_version', Types::INTEGER);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('organization_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('record_id', Types::GUID);
        $table->addColumn('record_identity_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('record_version', Types::INTEGER);
        $table->addColumn('revision_number', Types::INTEGER);
        $table->addColumn('action', Types::STRING, ['length' => 64]);
        $table->addColumn('actor_id', Types::STRING, ['length' => 191]);
        $table->addColumn('snapshot', Types::JSON);
        $table->addColumn('checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('changed_fields', Types::JSON);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'id');
        $table->addUniqueIndex(
            ['definition_id', 'record_id', 'revision_number'],
            'uniq_brecord_revision_number',
        );
        $table->addIndex(['site_identifier', 'definition_id', 'record_id'], 'idx_brecord_revision_record');
        $table->addIndex(
            ['definition_id', 'site_identifier', 'record_identity_digest'],
            'idx_brecord_revision_identity',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_definitions'),
            ['definition_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_brecord_revision_definition',
        );

        return $table;
    }

    private function commandIdempotency(): Table
    {
        $table = new Table($this->tables->raw('business_command_idempotency'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('scope_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('organization_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('actor_id', Types::STRING, ['length' => 191]);
        $table->addColumn('operation', Types::STRING, ['length' => 96]);
        $table->addColumn('operation_id', Types::STRING, ['length' => 128]);
        $table->addColumn('request_fingerprint', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('authorization_fingerprint', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('state', Types::STRING, ['length' => 24]);
        $table->addColumn('lease_owner', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('lease_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('result', Types::JSON, ['notnull' => false]);
        $table->addColumn('result_checksum', Types::STRING, [
            'length' => 64, 'fixed' => true, 'notnull' => false,
        ]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'id');
        $table->addUniqueIndex(['scope_digest'], 'uniq_bcommand_idempotency_scope');
        $table->addIndex(['expires_at', 'state'], 'idx_bcommand_idempotency_expiry');

        return $table;
    }

    private function ensureFence(Connection $database): void
    {
        $exists = $database->fetchOne(sprintf(
            'SELECT singleton_key FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('business_schema_fence'),
        ));
        if ($exists !== false) {
            return;
        }

        $database->insert($this->tables->raw('business_schema_fence'), [
            'singleton_key' => 1,
            'fence' => 0,
            'updated_at' => $this->now(),
        ], [
            'singleton_key' => Types::SMALLINT,
            'fence' => Types::BIGINT,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private function ensureCapabilities(Connection $database): void
    {
        foreach (self::CAPABILITIES as $code => $description) {
            $exists = $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$code]);
            if ($exists === false) {
                $database->insert($this->tables->raw('capabilities'), [
                    'code' => $code,
                    'description' => $description,
                ]);
            } else {
                $database->update(
                    $this->tables->raw('capabilities'),
                    ['description' => $description],
                    ['code' => $code],
                );
            }
        }

        $roles = $database->fetchAllAssociative(sprintf(
            'SELECT id FROM %s WHERE code = ?',
            $this->tables->quoted('roles'),
        ), ['administrator']);
        $changedUsers = false;
        foreach ($roles as $role) {
            $roleId = $role['id'] ?? null;
            if (!is_string($roleId)) {
                throw new RuntimeException('The stored administrator role identity is invalid.');
            }
            foreach (array_keys(self::CAPABILITIES) as $capability) {
                $grant = $database->fetchOne(sprintf(
                    'SELECT id FROM %s WHERE role_id = ? AND capability_code = ? '
                    . "AND scope_type = 'global' AND scope_identifier IS NULL",
                    $this->tables->quoted('role_capability_grants'),
                ), [$roleId, $capability]);
                if ($grant !== false) {
                    continue;
                }
                $database->insert($this->tables->raw('role_capability_grants'), [
                    'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'kumwe:administrator:' . $capability)->toString(),
                    'role_id' => $roleId,
                    'capability_code' => $capability,
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                    'granted_at' => $this->now(),
                    'granted_by' => null,
                ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
                $changedUsers = true;
            }
        }

        if (
            $changedUsers
            && $database->createSchemaManager()->introspectTableByUnquotedName($this->tables->raw('users'))
                ->hasColumn('security_epoch')
        ) {
            $database->executeStatement(sprintf(
                'UPDATE %s SET security_epoch = security_epoch + 1 WHERE id IN ('
                . 'SELECT DISTINCT ur.user_id FROM %s ur INNER JOIN %s r ON r.id = ur.role_id WHERE r.code = ?)',
                $this->tables->quoted('users'),
                $this->tables->quoted('user_roles'),
                $this->tables->quoted('roles'),
            ), ['administrator']);
        }
    }

    private function reconcileOrderedLinesFieldType(Connection $database): void
    {
        $type = null;
        foreach (BuiltInFieldTypes::all() as $candidate) {
            if ($candidate->id === 'core.ordered_lines') {
                $type = $candidate;
                break;
            }
        }
        if ($type === null) {
            throw new RuntimeException('The core ordered-lines field type is not registered.');
        }

        $row = $database->fetchAssociative(sprintf(
            'SELECT owner_type, owner_identifier FROM %s WHERE identifier = ?',
            $this->tables->quoted('business_field_types'),
        ), [$type->id]);
        if ($row === false) {
            return;
        }
        if (($row['owner_type'] ?? null) !== 'core' || ($row['owner_identifier'] ?? null) !== 'core') {
            throw new RuntimeException('The persisted core ordered-lines field type has an invalid owner.');
        }

        $payload = $type->toArray();
        $database->update($this->tables->raw('business_field_types'), [
            'source_version' => 'core',
            'active' => true,
            'checksum' => CanonicalDefinitionJson::checksum($payload),
            'canonical_payload' => $payload,
            'updated_at' => $this->now(),
        ], ['identifier' => $type->id], [
            'active' => Types::BOOLEAN,
            'canonical_payload' => Types::JSON,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @param non-empty-string $first
     * @param non-empty-string ...$rest
     */
    private function primary(Table $table, string $first, string ...$rest): void
    {
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames($first, ...$rest)->create(),
        );
    }
}
