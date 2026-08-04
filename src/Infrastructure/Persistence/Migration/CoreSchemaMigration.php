<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

final readonly class CoreSchemaMigration implements Migration
{
    public const ID = '20260804010000_create_kumwe_core';

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
            throw new \RuntimeException('The core migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $schema = new Schema();
        $this->identity($schema);
        $this->content($schema);
        $this->navigation($schema);
        $this->extensions($schema);
        $this->automation($schema);
        $this->runtime($schema);
        $this->foreignKeys($schema);

        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }

        $this->seed($database);
    }

    private function identity(Schema $schema): void
    {
        $users = $this->table($schema, 'users');
        $this->primaryKey($users);
        $users->addColumn('email', Types::STRING, ['length' => 254]);
        $users->addColumn('email_normalized', Types::STRING, ['length' => 254]);
        $users->addColumn('display_name', Types::STRING, ['length' => 191]);
        $users->addColumn('status', Types::STRING, ['length' => 32]);
        $users->addColumn('version', Types::INTEGER, ['default' => 1]);
        $this->timestamps($users);
        $users->addUniqueIndex(['email_normalized'], 'uniq_users_email');

        $credentials = $this->table($schema, 'password_credentials');
        $credentials->addColumn('user_id', Types::GUID);
        $credentials->addColumn('password_hash', Types::TEXT);
        $credentials->addColumn('changed_at', Types::DATETIME_IMMUTABLE);
        $this->primaryKey($credentials, 'user_id');

        $roles = $this->table($schema, 'roles');
        $this->primaryKey($roles);
        $roles->addColumn('code', Types::STRING, ['length' => 64]);
        $roles->addColumn('name', Types::STRING, ['length' => 191]);
        $roles->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $roles->addUniqueIndex(['code'], 'uniq_roles_code');

        $userRoles = $this->table($schema, 'user_roles');
        $userRoles->addColumn('user_id', Types::GUID);
        $userRoles->addColumn('role_id', Types::GUID);
        $userRoles->addColumn('assigned_at', Types::DATETIME_IMMUTABLE);
        $userRoles->addColumn('assigned_by', Types::GUID, ['notnull' => false]);
        $this->primaryKey($userRoles, 'user_id', 'role_id');

        $capabilities = $this->table($schema, 'capabilities');
        $capabilities->addColumn('code', Types::STRING, ['length' => 191]);
        $capabilities->addColumn('description', Types::STRING, ['length' => 500, 'default' => '']);
        $this->primaryKey($capabilities, 'code');

        $grants = $this->table($schema, 'role_capability_grants');
        $this->primaryKey($grants);
        $grants->addColumn('role_id', Types::GUID);
        $grants->addColumn('capability_code', Types::STRING, ['length' => 191]);
        $grants->addColumn('scope_type', Types::STRING, ['length' => 63]);
        $grants->addColumn('scope_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $grants->addColumn('granted_at', Types::DATETIME_IMMUTABLE);
        $grants->addColumn('granted_by', Types::GUID, ['notnull' => false]);
        $grants->addIndex(['role_id', 'capability_code'], 'idx_grants_lookup');

        $audit = $this->table($schema, 'audit_events');
        $this->primaryKey($audit);
        $audit->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $audit->addColumn('actor_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $audit->addColumn('action', Types::STRING, ['length' => 127]);
        $audit->addColumn('subject_type', Types::STRING, ['length' => 63]);
        $audit->addColumn('subject_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $audit->addColumn('outcome', Types::STRING, ['length' => 31]);
        $audit->addColumn('metadata', Types::JSON);
        $audit->addIndex(['occurred_at'], 'idx_audit_time');
        $audit->addIndex(['actor_id', 'occurred_at'], 'idx_audit_actor');

        $sessions = $this->table($schema, 'administrator_sessions');
        $this->primaryKey($sessions);
        $sessions->addColumn('user_id', Types::GUID);
        $sessions->addColumn('token_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $sessions->addColumn('csrf_token', Types::STRING, ['length' => 128]);
        $sessions->addColumn('ip_digest', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $sessions->addColumn('user_agent_digest', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $sessions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $sessions->addColumn('last_seen_at', Types::DATETIME_IMMUTABLE);
        $sessions->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $sessions->addUniqueIndex(['token_digest'], 'uniq_admin_session_token');
        $sessions->addIndex(['expires_at'], 'idx_admin_session_expiry');

        $tokens = $this->table($schema, 'api_tokens');
        $this->primaryKey($tokens);
        $tokens->addColumn('subject_id', Types::GUID);
        $tokens->addColumn('token_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $tokens->addColumn('name', Types::STRING, ['length' => 191]);
        $tokens->addColumn('capabilities', Types::JSON);
        $tokens->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tokens->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tokens->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $tokens->addColumn('last_used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tokens->addUniqueIndex(['token_digest'], 'uniq_api_token_digest');
    }

    private function content(Schema $schema): void
    {
        $workflows = $this->table($schema, 'workflows');
        $this->primaryKey($workflows);
        $workflows->addColumn('handle', Types::STRING, ['length' => 100]);
        $workflows->addColumn('name', Types::STRING, ['length' => 255]);
        $workflows->addColumn('version', Types::INTEGER, ['default' => 1]);
        $this->timestamps($workflows);
        $workflows->addUniqueIndex(['handle'], 'uniq_workflow_handle');

        $states = $this->table($schema, 'workflow_states');
        $states->addColumn('workflow_id', Types::GUID);
        $states->addColumn('state_key', Types::STRING, ['length' => 40]);
        $states->addColumn('name', Types::STRING, ['length' => 255]);
        $states->addColumn('is_initial', Types::BOOLEAN, ['default' => false]);
        $states->addColumn('is_public', Types::BOOLEAN, ['default' => false]);
        $this->primaryKey($states, 'workflow_id', 'state_key');

        $transitions = $this->table($schema, 'workflow_transitions');
        $transitions->addColumn('workflow_id', Types::GUID);
        $transitions->addColumn('from_state', Types::STRING, ['length' => 40]);
        $transitions->addColumn('to_state', Types::STRING, ['length' => 40]);
        $transitions->addColumn('required_capability', Types::STRING, ['length' => 191, 'notnull' => false]);
        $this->primaryKey($transitions, 'workflow_id', 'from_state', 'to_state');

        $types = $this->table($schema, 'content_types');
        $this->primaryKey($types);
        $types->addColumn('workflow_id', Types::GUID);
        $types->addColumn('handle', Types::STRING, ['length' => 100]);
        $types->addColumn('name', Types::STRING, ['length' => 255]);
        $types->addColumn('field_schema', Types::JSON);
        $types->addColumn('version', Types::INTEGER, ['default' => 1]);
        $this->timestamps($types);
        $types->addUniqueIndex(['handle'], 'uniq_content_type_handle');

        $entries = $this->table($schema, 'content_entries');
        $this->primaryKey($entries);
        $entries->addColumn('content_type_id', Types::GUID);
        $entries->addColumn('workflow_id', Types::GUID);
        $entries->addColumn('workflow_state_key', Types::STRING, ['length' => 40]);
        $entries->addColumn('title', Types::STRING, ['length' => 255]);
        $entries->addColumn('slug', Types::STRING, ['length' => 160]);
        $entries->addColumn('data', Types::JSON);
        $entries->addColumn('publish_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $entries->addColumn('unpublish_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $entries->addColumn('version', Types::INTEGER, ['default' => 1]);
        $this->timestamps($entries);
        $entries->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $entries->addUniqueIndex(['content_type_id', 'slug'], 'uniq_content_slug');
        $entries->addIndex(['workflow_state_key', 'publish_at', 'unpublish_at'], 'idx_content_publication');

        $revisions = $this->table($schema, 'content_revisions');
        $this->primaryKey($revisions);
        $revisions->addColumn('content_entry_id', Types::GUID);
        $revisions->addColumn('revision_number', Types::INTEGER);
        $revisions->addColumn('snapshot', Types::JSON);
        $revisions->addColumn('checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $revisions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $revisions->addUniqueIndex(['content_entry_id', 'revision_number'], 'uniq_content_revision');
    }

    private function navigation(Schema $schema): void
    {
        $menus = $this->table($schema, 'navigation_menus');
        $this->primaryKey($menus);
        $menus->addColumn('handle', Types::STRING, ['length' => 100]);
        $menus->addColumn('title', Types::STRING, ['length' => 255]);
        $menus->addColumn('version', Types::INTEGER, ['default' => 1]);
        $this->timestamps($menus);
        $menus->addUniqueIndex(['handle'], 'uniq_menu_handle');

        $items = $this->table($schema, 'navigation_items');
        $this->primaryKey($items);
        $items->addColumn('menu_id', Types::GUID);
        $items->addColumn('parent_id', Types::GUID, ['notnull' => false]);
        $items->addColumn('title', Types::STRING, ['length' => 255]);
        $items->addColumn('slug', Types::STRING, ['length' => 160]);
        // Keep the composite unique index within MySQL/MariaDB's portable
        // utf8mb4 index-width limit while still allowing deeply nested menus.
        $items->addColumn('path', Types::STRING, ['length' => 512]);
        $items->addColumn('position', Types::INTEGER, ['default' => 0]);
        $items->addColumn('version', Types::INTEGER, ['default' => 1]);
        $this->timestamps($items);
        $items->addUniqueIndex(['menu_id', 'path'], 'uniq_menu_item_path');
        $items->addIndex(['menu_id', 'parent_id', 'position'], 'idx_menu_item_order');
    }

    private function extensions(Schema $schema): void
    {
        $extensions = $this->table($schema, 'extensions');
        $this->primaryKey($extensions);
        $extensions->addColumn('identifier', Types::STRING, ['length' => 127]);
        $extensions->addColumn('extension_type', Types::STRING, ['length' => 32]);
        $extensions->addColumn('installed_version', Types::STRING, ['length' => 128]);
        $extensions->addColumn('status', Types::STRING, ['length' => 32]);
        $extensions->addColumn('service_provider', Types::STRING, ['length' => 255]);
        $extensions->addColumn('runtime_path', Types::STRING, ['length' => 1024, 'notnull' => false]);
        $extensions->addColumn('registry_version', Types::BIGINT, ['default' => 0]);
        $extensions->addColumn('installed_at', Types::DATETIME_IMMUTABLE);
        $extensions->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $extensions->addUniqueIndex(['identifier'], 'uniq_extension_identifier');

        $releases = $this->table($schema, 'extension_releases');
        $this->primaryKey($releases);
        $releases->addColumn('extension_id', Types::GUID);
        $releases->addColumn('version', Types::STRING, ['length' => 128]);
        $releases->addColumn('manifest', Types::JSON);
        $releases->addColumn('package_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $releases->addColumn('signature_algorithm', Types::STRING, ['length' => 32, 'notnull' => false]);
        $releases->addColumn('signing_key_id', Types::STRING, ['length' => 127, 'notnull' => false]);
        $releases->addColumn('signature_base64', Types::STRING, ['length' => 256, 'notnull' => false]);
        $releases->addColumn('released_at', Types::DATETIME_IMMUTABLE);
        $releases->addColumn('installed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $releases->addUniqueIndex(['extension_id', 'version'], 'uniq_extension_release');

        $dependencies = $this->table($schema, 'extension_dependencies');
        $dependencies->addColumn('release_id', Types::GUID);
        $dependencies->addColumn('required_identifier', Types::STRING, ['length' => 127]);
        $dependencies->addColumn('version_constraint', Types::STRING, ['length' => 255]);
        $dependencies->addColumn('optional', Types::BOOLEAN, ['default' => false]);
        $this->primaryKey($dependencies, 'release_id', 'required_identifier');

        $keys = $this->table($schema, 'extension_trust_keys');
        $keys->addColumn('key_id', Types::STRING, ['length' => 127]);
        $keys->addColumn('algorithm', Types::STRING, ['length' => 32]);
        $keys->addColumn('public_key_base64', Types::STRING, ['length' => 128]);
        $keys->addColumn('enabled', Types::BOOLEAN, ['default' => true]);
        $keys->addColumn('added_at', Types::DATETIME_IMMUTABLE);
        $keys->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->primaryKey($keys, 'key_id');

        $generation = $this->table($schema, 'extension_runtime_generation');
        $generation->addColumn('singleton_key', Types::SMALLINT);
        $generation->addColumn('generation', Types::BIGINT, ['default' => 0]);
        $generation->addColumn('rebuilt_at', Types::DATETIME_IMMUTABLE);
        $this->primaryKey($generation, 'singleton_key');

        $migrations = $this->table($schema, 'extension_migrations');
        $migrations->addColumn('extension_identifier', Types::STRING, ['length' => 127]);
        $migrations->addColumn('migration_id', Types::STRING, ['length' => 96]);
        $migrations->addColumn('extension_version', Types::STRING, ['length' => 128]);
        $migrations->addColumn('applied_at', Types::DATETIME_IMMUTABLE);
        $this->primaryKey($migrations, 'extension_identifier', 'migration_id');
    }

    private function automation(Schema $schema): void
    {
        $schedules = $this->table($schema, 'schedules');
        $this->primaryKey($schedules);
        $schedules->addColumn('name', Types::STRING, ['length' => 160]);
        $schedules->addColumn('cron_expression', Types::STRING, ['length' => 120]);
        $schedules->addColumn('timezone', Types::STRING, ['length' => 80]);
        $schedules->addColumn('queue', Types::STRING, ['length' => 64, 'default' => 'default']);
        $schedules->addColumn('job_type', Types::STRING, ['length' => 128]);
        $schedules->addColumn('job_schema_version', Types::INTEGER, ['default' => 1]);
        $schedules->addColumn('payload', Types::JSON);
        $schedules->addColumn('priority', Types::SMALLINT, ['default' => 0]);
        $schedules->addColumn('maximum_attempts', Types::SMALLINT, ['default' => 5]);
        $schedules->addColumn('enabled', Types::BOOLEAN, ['default' => true]);
        $schedules->addColumn('next_run_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $schedules->addColumn('last_run_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $schedules->addColumn('version', Types::INTEGER, ['default' => 1]);
        $this->timestamps($schedules);
        $schedules->addUniqueIndex(['name'], 'uniq_schedule_name');
        $schedules->addIndex(['enabled', 'next_run_at'], 'idx_schedule_due');

        $jobs = $this->table($schema, 'jobs');
        $this->primaryKey($jobs);
        $jobs->addColumn('queue', Types::STRING, ['length' => 64, 'default' => 'default']);
        $jobs->addColumn('job_type', Types::STRING, ['length' => 128]);
        $jobs->addColumn('schema_version', Types::INTEGER, ['default' => 1]);
        $jobs->addColumn('payload', Types::JSON);
        $jobs->addColumn('priority', Types::SMALLINT, ['default' => 0]);
        $jobs->addColumn('status', Types::STRING, ['length' => 16, 'default' => 'pending']);
        $jobs->addColumn('available_at', Types::DATETIME_IMMUTABLE);
        $jobs->addColumn('lease_owner', Types::STRING, ['length' => 128, 'notnull' => false]);
        $jobs->addColumn('lease_acquired_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $jobs->addColumn('lease_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $jobs->addColumn('attempts', Types::SMALLINT, ['default' => 0]);
        $jobs->addColumn('maximum_attempts', Types::SMALLINT, ['default' => 5]);
        $jobs->addColumn('schedule_id', Types::GUID, ['notnull' => false]);
        $jobs->addColumn('scheduled_for', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $jobs->addColumn('occurrence_key', Types::STRING, ['length' => 128, 'notnull' => false]);
        $jobs->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->timestamps($jobs);
        $jobs->addIndex(['queue', 'status', 'priority', 'available_at'], 'idx_job_claim');
        $jobs->addUniqueIndex(['occurrence_key'], 'uniq_job_occurrence');

        $failed = $this->table($schema, 'failed_jobs');
        $this->primaryKey($failed);
        $failed->addColumn('job_id', Types::GUID);
        $failed->addColumn('queue', Types::STRING, ['length' => 64]);
        $failed->addColumn('job_type', Types::STRING, ['length' => 128]);
        $failed->addColumn('schema_version', Types::INTEGER);
        $failed->addColumn('payload', Types::JSON);
        $failed->addColumn('attempts', Types::SMALLINT);
        $failed->addColumn('maximum_attempts', Types::SMALLINT);
        $failed->addColumn('failure_classification', Types::STRING, ['length' => 16]);
        $failed->addColumn('exception_type', Types::STRING, ['length' => 255]);
        $failed->addColumn('error_message', Types::TEXT);
        $failed->addColumn('failed_at', Types::DATETIME_IMMUTABLE);
        $failed->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $failed->addUniqueIndex(['job_id'], 'uniq_failed_job');

        $heartbeats = $this->table($schema, 'worker_heartbeats');
        $heartbeats->addColumn('worker_id', Types::STRING, ['length' => 128]);
        $heartbeats->addColumn('queue', Types::STRING, ['length' => 64]);
        $heartbeats->addColumn('process_id', Types::INTEGER, ['notnull' => false]);
        $heartbeats->addColumn('application_release', Types::STRING, ['length' => 128]);
        $heartbeats->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $heartbeats->addColumn('heartbeat_at', Types::DATETIME_IMMUTABLE);
        $heartbeats->addColumn('current_job_id', Types::GUID, ['notnull' => false]);
        $this->primaryKey($heartbeats, 'worker_id');
    }

    private function runtime(Schema $schema): void
    {
        $settings = $this->table($schema, 'site_settings');
        $settings->addColumn('setting_key', Types::STRING, ['length' => 191]);
        $settings->addColumn('setting_value', Types::JSON);
        $settings->addColumn('version', Types::INTEGER, ['default' => 1]);
        $settings->addColumn('updated_by', Types::GUID, ['notnull' => false]);
        $settings->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primaryKey($settings, 'setting_key');

        $idempotency = $this->table($schema, 'idempotency');
        $this->primaryKey($idempotency);
        $idempotency->addColumn('idempotency_key', Types::STRING, ['length' => 128]);
        $idempotency->addColumn('subject', Types::STRING, ['length' => 255]);
        $idempotency->addColumn('operation', Types::STRING, ['length' => 160]);
        $idempotency->addColumn('request_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $idempotency->addColumn('state', Types::STRING, ['length' => 16, 'default' => 'in_progress']);
        $idempotency->addColumn('result_status', Types::SMALLINT, ['notnull' => false]);
        $idempotency->addColumn('result_body', Types::JSON, ['notnull' => false]);
        $idempotency->addColumn('result_headers', Types::JSON, ['notnull' => false]);
        $idempotency->addColumn('result_body_digest', Types::STRING, [
            'length' => 64,
            'fixed' => true,
            'notnull' => false,
        ]);
        $idempotency->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $idempotency->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $idempotency->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $idempotency->addUniqueIndex(['subject', 'operation', 'idempotency_key'], 'uniq_idempotency_scope');
        $idempotency->addIndex(['expires_at'], 'idx_idempotency_expiry');
    }

    private function foreignKeys(Schema $schema): void
    {
        $schema->getTable($this->tables->raw('password_credentials'))->addForeignKeyConstraint(
            $this->tables->raw('users'),
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_password_user',
        );
        $schema->getTable($this->tables->raw('user_roles'))->addForeignKeyConstraint(
            $this->tables->raw('users'),
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_user_roles_user',
        );
        $schema->getTable($this->tables->raw('user_roles'))->addForeignKeyConstraint(
            $this->tables->raw('roles'),
            ['role_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_user_roles_role',
        );
        $schema->getTable($this->tables->raw('role_capability_grants'))->addForeignKeyConstraint(
            $this->tables->raw('roles'),
            ['role_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_grants_role',
        );
        $schema->getTable($this->tables->raw('role_capability_grants'))->addForeignKeyConstraint(
            $this->tables->raw('capabilities'),
            ['capability_code'],
            ['code'],
            ['onDelete' => 'CASCADE'],
            'fk_grants_capability',
        );
        $schema->getTable($this->tables->raw('administrator_sessions'))->addForeignKeyConstraint(
            $this->tables->raw('users'),
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_session_user',
        );
        $schema->getTable($this->tables->raw('api_tokens'))->addForeignKeyConstraint(
            $this->tables->raw('users'),
            ['subject_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_token_user',
        );
        $schema->getTable($this->tables->raw('workflow_states'))->addForeignKeyConstraint(
            $this->tables->raw('workflows'),
            ['workflow_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_state_workflow',
        );
        $schema->getTable($this->tables->raw('content_types'))->addForeignKeyConstraint(
            $this->tables->raw('workflows'),
            ['workflow_id'],
            ['id'],
            [],
            'fk_type_workflow',
        );
        $schema->getTable($this->tables->raw('content_entries'))->addForeignKeyConstraint(
            $this->tables->raw('content_types'),
            ['content_type_id'],
            ['id'],
            [],
            'fk_entry_type',
        );
        $schema->getTable($this->tables->raw('content_revisions'))->addForeignKeyConstraint(
            $this->tables->raw('content_entries'),
            ['content_entry_id'],
            ['id'],
            [],
            'fk_revision_entry',
        );
        $schema->getTable($this->tables->raw('navigation_items'))->addForeignKeyConstraint(
            $this->tables->raw('navigation_menus'),
            ['menu_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_item_menu',
        );
        $schema->getTable($this->tables->raw('extension_releases'))->addForeignKeyConstraint(
            $this->tables->raw('extensions'),
            ['extension_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_release_extension',
        );
        $schema->getTable($this->tables->raw('extension_dependencies'))->addForeignKeyConstraint(
            $this->tables->raw('extension_releases'),
            ['release_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_dependency_release',
        );
    }

    private function seed(Connection $database): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $workflowId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';
        $contentTypeId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb402';
        $database->insert($this->tables->raw('workflows'), [
            'id' => $workflowId,
            'handle' => 'editorial',
            'name' => 'Editorial workflow',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);

        foreach (
            [
            ['draft', 'Draft', true, false],
            ['review', 'In review', false, false],
            ['published', 'Published', false, true],
            ['archived', 'Archived', false, false],
            ] as [$key, $name, $initial, $public]
        ) {
            $database->insert($this->tables->raw('workflow_states'), [
                'workflow_id' => $workflowId,
                'state_key' => $key,
                'name' => $name,
                'is_initial' => $initial,
                'is_public' => $public,
            ], ['is_initial' => Types::BOOLEAN, 'is_public' => Types::BOOLEAN]);
        }

        foreach (
            [
            ['draft', 'review', 'content.submit'],
            ['draft', 'archived', 'content.archive'],
            ['review', 'draft', 'content.review'],
            ['review', 'published', 'content.publish'],
            ['review', 'archived', 'content.archive'],
            ['published', 'draft', 'content.unpublish'],
            ['published', 'archived', 'content.archive'],
            ['archived', 'draft', 'content.restore'],
            ] as [$from, $to, $capability]
        ) {
            $database->insert($this->tables->raw('workflow_transitions'), [
                'workflow_id' => $workflowId,
                'from_state' => $from,
                'to_state' => $to,
                'required_capability' => $capability,
            ]);
        }

        $database->insert($this->tables->raw('content_types'), [
            'id' => $contentTypeId,
            'workflow_id' => $workflowId,
            'handle' => 'page',
            'name' => 'Page',
            'field_schema' => ['type' => 'object', 'properties' => ['body' => ['type' => 'string']]],
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'field_schema' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);

        foreach ($this->capabilities() as $code => $description) {
            $database->insert($this->tables->raw('capabilities'), [
                'code' => $code,
                'description' => $description,
            ]);
        }

        $database->insert($this->tables->raw('extension_runtime_generation'), [
            'singleton_key' => 1,
            'generation' => 0,
            'rebuilt_at' => $now,
        ], ['rebuilt_at' => Types::DATETIME_IMMUTABLE]);
        $database->insert($this->tables->raw('schedules'), [
            'id' => '00000000-0000-7000-8000-000000000801',
            'name' => 'Purge expired administrator sessions',
            'cron_expression' => '*/15 * * * *',
            'timezone' => 'UTC',
            'queue' => 'default',
            'job_type' => 'system.sessions.purge',
            'job_schema_version' => 1,
            'payload' => [],
            'priority' => 0,
            'maximum_attempts' => 5,
            'enabled' => true,
            'next_run_at' => $now->modify('+15 minutes'),
            'last_run_at' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'payload' => Types::JSON,
            'enabled' => Types::BOOLEAN,
            'next_run_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /** @return array<string, string> */
    private function capabilities(): array
    {
        return [
            'administrator.access' => 'Access the administrator application.',
            'automation.manage' => 'Manage schedules, queues and failed jobs.',
            'content.archive' => 'Archive content.',
            'content.create' => 'Create content.',
            'content.delete' => 'Trash content.',
            'content.publish' => 'Publish content.',
            'content.read' => 'Read non-public content.',
            'content.restore' => 'Restore archived or trashed content.',
            'content.review' => 'Review submitted content.',
            'content.submit' => 'Submit content for review.',
            'content.unpublish' => 'Unpublish content.',
            'content.update' => 'Update content.',
            'extensions.manage' => 'Manage extensions and templates.',
            'navigation.manage' => 'Manage menus and navigation items.',
            'settings.manage' => 'Manage site settings.',
            'users.manage' => 'Manage users, roles, permissions and tokens.',
        ];
    }

    private function table(Schema $schema, string $name): Table
    {
        return $schema->createTable($this->tables->raw($name));
    }

    /**
     * @param non-empty-string $firstColumn
     * @param non-empty-string ...$otherColumns
     */
    private function primaryKey(Table $table, string $firstColumn = 'id', string ...$otherColumns): void
    {
        if ($firstColumn === 'id') {
            $table->addColumn('id', Types::GUID);
        }

        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames($firstColumn, ...$otherColumns)
                ->create(),
        );
    }

    private function timestamps(Table $table): void
    {
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
    }
}
