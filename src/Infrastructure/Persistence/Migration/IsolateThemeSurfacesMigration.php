<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class IsolateThemeSurfacesMigration implements RepeatableMigration
{
    public const ID = '20260805090000_isolate_theme_surfaces';

    public function __construct(private TableNames $tables)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);

        if (!is_string($checksum)) {
            throw new RuntimeException('The theme-surface migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $schema = new Schema();
        $activations = $schema->createTable($this->tables->raw('theme_activations'));
        $activations->addColumn('surface', Types::STRING, ['length' => 32]);
        $activations->addColumn('extension_id', Types::GUID, ['notnull' => false]);
        $activations->addColumn('version', Types::BIGINT, ['default' => 1]);
        $activations->addColumn('activated_by', Types::STRING, ['length' => 191, 'notnull' => false]);
        $activations->addColumn('activated_at', Types::DATETIME_IMMUTABLE);
        $activations->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('surface')->create(),
        );
        $activations->addForeignKeyConstraint(
            $this->tables->raw('extensions'),
            ['extension_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_theme_activation_' . substr(hash('sha256', $this->tables->raw('theme_activations')), 0, 16),
        );

        $siteActivations = $schema->createTable($this->tables->raw('site_theme_activations'));
        $siteActivations->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $siteActivations->addColumn('extension_id', Types::GUID, ['notnull' => false]);
        $siteActivations->addColumn('version', Types::BIGINT, ['default' => 1]);
        $siteActivations->addColumn('activated_by', Types::STRING, ['length' => 191, 'notnull' => false]);
        $siteActivations->addColumn('activated_at', Types::DATETIME_IMMUTABLE);
        $siteActivations->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('site_identifier')->create(),
        );
        $siteActivations->addForeignKeyConstraint(
            $this->tables->raw('extensions'),
            ['extension_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_site_theme_activation_' . substr(hash('sha256', $this->tables->raw('site_theme_activations')), 0, 16),
        );

        $publications = $schema->createTable($this->tables->raw('extension_runtime_publications'));
        $publications->addColumn('generation', Types::BIGINT);
        $publications->addColumn('state_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $publications->addColumn('publication_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $publications->addColumn('trust_hmac', Types::STRING, ['length' => 64, 'fixed' => true]);
        $publications->addColumn('signing_key_id', Types::STRING, ['length' => 127]);
        $publications->addColumn('action', Types::STRING, ['length' => 127]);
        $publications->addColumn('payload', Types::JSON);
        $publications->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $publications->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('generation')->create(),
        );

        $materializations = $schema->createTable($this->tables->raw('extension_runtime_materializations'));
        $materializations->addColumn('replica_id', Types::STRING, ['length' => 64]);
        $materializations->addColumn('deployment_id', Types::STRING, ['length' => 127]);
        $materializations->addColumn('replica_name', Types::STRING, ['length' => 127]);
        $materializations->addColumn('process_id', Types::STRING, ['length' => 127]);
        $materializations->addColumn('generation', Types::BIGINT);
        $materializations->addColumn('publication_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $materializations->addColumn('trust_hmac', Types::STRING, ['length' => 64, 'fixed' => true]);
        $materializations->addColumn('materialized_at', Types::DATETIME_IMMUTABLE);
        $materializations->addColumn('last_seen_at', Types::DATETIME_IMMUTABLE);
        $materializations->addColumn('lease_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $materializations->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('replica_id')->create(),
        );
        $materializations->addIndex(['generation', 'last_seen_at'], 'idx_runtime_materialization_generation');

        $retirements = $schema->createTable($this->tables->raw('extension_runtime_retirements'));
        $retirements->addColumn('id', Types::GUID);
        $retirements->addColumn('runtime_path', Types::STRING, ['length' => 1024]);
        $retirements->addColumn('runtime_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $retirements->addColumn('retire_after_generation', Types::BIGINT);
        $retirements->addColumn('retain_until', Types::DATETIME_IMMUTABLE);
        $retirements->addColumn('cleaned_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $retirements->addColumn('claim_token', Types::STRING, ['length' => 64, 'notnull' => false]);
        $retirements->addColumn('claim_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $retirements->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );
        $retirements->addUniqueIndex(['runtime_sha256'], 'uniq_runtime_retirement_path');
        $retirements->addIndex(
            ['cleaned_at', 'retain_until', 'retire_after_generation'],
            'idx_runtime_retirement_ready',
        );

        $fence = $schema->createTable($this->tables->raw('extension_registry_fence'));
        $fence->addColumn('singleton_key', Types::SMALLINT);
        $fence->addColumn('fence', Types::BIGINT, ['default' => 0]);
        $fence->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $fence->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('singleton_key')->create(),
        );

        $operations = $schema->createTable($this->tables->raw('extension_install_operations'));
        $operations->addColumn('operation_id', Types::GUID);
        $operations->addColumn('identifier', Types::STRING, ['length' => 191]);
        $operations->addColumn('version', Types::STRING, ['length' => 64]);
        $operations->addColumn('package_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $operations->addColumn('runtime_path', Types::STRING, ['length' => 1024]);
        $operations->addColumn('staging_path', Types::STRING, ['length' => 1024]);
        $operations->addColumn('actor_id', Types::STRING, ['length' => 191]);
        $operations->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $operations->addColumn('signing_key_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $operations->addColumn('package_signature', Types::TEXT, ['notnull' => false]);
        $operations->addColumn('state', Types::STRING, ['length' => 32]);
        $operations->addColumn('transaction_outcome', Types::STRING, ['length' => 32]);
        $operations->addColumn('fence', Types::BIGINT);
        $operations->addColumn('last_error', Types::TEXT, ['notnull' => false]);
        $operations->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $operations->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $operations->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('operation_id')->create(),
        );
        $operations->addUniqueIndex(
            ['identifier', 'version', 'package_sha256'],
            'uniq_extension_install_operation',
        );
        $operations->addIndex(['state', 'updated_at'], 'idx_extension_install_reconcile');

        $schemaManager = $database->createSchemaManager();
        foreach ($schema->getTables() as $table) {
            if (!$schemaManager->tablesExist([$table->getName()])) {
                $schemaManager->createTable($table);
            }
        }

        $extensionMigrations = $schemaManager->introspectTable($this->tables->raw('extension_migrations'));
        if (!$extensionMigrations->hasColumn('migration_sha256')) {
            $before = $schemaManager->introspectSchema();
            $after = clone $before;
            $after->getTable($this->tables->raw('extension_migrations'))->addColumn(
                'migration_sha256',
                Types::STRING,
                ['length' => 64, 'fixed' => true, 'notnull' => false],
            );
            $difference = $schemaManager->createComparator()->compareSchemas($before, $after);
            foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
                $database->executeStatement($statement);
            }
        }

        $fenceExists = $database->fetchOne(sprintf(
            'SELECT singleton_key FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_registry_fence'),
        ));
        if (!is_numeric($fenceExists)) {
            $database->insert($this->tables->raw('extension_registry_fence'), [
                'singleton_key' => 1,
                'fence' => 0,
                'updated_at' => new DateTimeImmutable(),
            ], ['fence' => Types::BIGINT, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        }

        $now = new DateTimeImmutable();
        $legacySiteTheme = $database->fetchOne(sprintf(
            "SELECT id FROM %s WHERE extension_type = 'template' AND status = 'active' "
            . 'ORDER BY updated_at DESC, identifier ASC',
            $this->tables->quoted('extensions'),
        ));
        $legacySiteTheme = is_string($legacySiteTheme) && $legacySiteTheme !== '' ? $legacySiteTheme : null;

        foreach (['site', 'administrator'] as $surface) {
            $existingActivation = $database->fetchOne(sprintf(
                'SELECT surface FROM %s WHERE surface = ?',
                $this->tables->quoted('theme_activations'),
            ), [$surface]);
            if (is_string($existingActivation)) {
                continue;
            }
            $database->insert($this->tables->raw('theme_activations'), [
                'surface' => $surface,
                'extension_id' => $surface === 'site' ? $legacySiteTheme : null,
                'version' => 1,
                'activated_by' => null,
                'activated_at' => $now,
            ], ['activated_at' => Types::DATETIME_IMMUTABLE]);
        }

        $defaultSiteActivation = $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE site_identifier = ?',
            $this->tables->quoted('site_theme_activations'),
        ), ['default']);
        if (!is_string($defaultSiteActivation)) {
            $database->insert($this->tables->raw('site_theme_activations'), [
                'site_identifier' => 'default',
                'extension_id' => $legacySiteTheme,
                'version' => 1,
                'activated_by' => null,
                'activated_at' => $now,
            ], ['activated_at' => Types::DATETIME_IMMUTABLE]);
        }
        $database->executeStatement(sprintf(
            'UPDATE %s SET extension_id = NULL, version = version + 1, activated_at = ? '
            . "WHERE surface = 'site' AND extension_id IS NOT NULL",
            $this->tables->quoted('theme_activations'),
        ), [$now], [Types::DATETIME_IMMUTABLE]);

        if ($legacySiteTheme !== null) {
            $database->executeStatement(sprintf(
                "UPDATE %s SET status = 'disabled', registry_version = registry_version + 1, updated_at = ? "
                . "WHERE extension_type = 'template' AND status = 'active' AND id <> ?",
                $this->tables->quoted('extensions'),
            ), [$now, $legacySiteTheme], [Types::DATETIME_IMMUTABLE, Types::GUID]);
        }

        $roleId = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE code = ?',
            $this->tables->quoted('roles'),
        ), ['administrator']);

        foreach ($this->capabilities() as $code => $description) {
            $existingCapability = $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$code]);
            if (!is_string($existingCapability)) {
                $database->insert($this->tables->raw('capabilities'), [
                    'code' => $code,
                    'description' => $description,
                ]);
            }

            if (is_string($roleId) && $roleId !== '') {
                $existingGrant = $database->fetchOne(sprintf(
                    'SELECT id FROM %s WHERE role_id = ? AND capability_code = ? AND scope_type = ?',
                    $this->tables->quoted('role_capability_grants'),
                ), [$roleId, $code, 'global']);
                if (is_string($existingGrant)) {
                    continue;
                }
                $database->insert($this->tables->raw('role_capability_grants'), [
                    'id' => Uuid::uuid7()->toString(),
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

    /** @return array<string, string> */
    private function capabilities(): array
    {
        return [
            'themes.site.manage' => 'Activate and recover the public site theme.',
            'themes.administrator.manage' => 'Activate administrator themes after step-up authentication.',
        ];
    }
}
