<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

final readonly class JobRecoveryMigration implements Migration
{
    public const ID = '20260805030000_recover_jobs_and_idempotency';

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
            throw new \RuntimeException('The job recovery migration checksum could not be calculated.');
        }
        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $schema = $database->createSchemaManager();
        $platform = $database->getDatabasePlatform();
        $jobsTable = $this->tables->raw('jobs');
        $idempotencyTable = $this->tables->raw('idempotency');
        if ($jobsTable === '' || $idempotencyTable === '') {
            throw new \RuntimeException('Migration table names must not be empty.');
        }
        $jobs = $schema->introspectTableByUnquotedName($jobsTable);
        $jobRecoveryIndex = $this->tables->raw('idx_job_recovery');
        if (!$jobs->hasColumn('lease_token')) {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD lease_token VARCHAR(36) DEFAULT NULL',
                $this->tables->quoted('jobs'),
            ));
        }
        if (!$jobs->hasIndex($jobRecoveryIndex)) {
            $database->executeStatement(sprintf(
                'CREATE INDEX %s ON %s (queue, status, lease_expires_at)',
                $database->quoteSingleIdentifier($jobRecoveryIndex),
                $this->tables->quoted('jobs'),
            ));
        }

        $idempotency = $schema->introspectTableByUnquotedName($idempotencyTable);
        $idempotencyLeaseIndex = $this->tables->raw('idx_idempotency_lease');
        if (!$idempotency->hasColumn('owner_token')) {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD owner_token VARCHAR(36) DEFAULT NULL',
                $this->tables->quoted('idempotency'),
            ));
        }
        if (!$idempotency->hasColumn('locked_until')) {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD locked_until %s DEFAULT NULL',
                $this->tables->quoted('idempotency'),
                $platform->getDateTimeTypeDeclarationSQL(['notnull' => false]),
            ));
        }
        if (!$idempotency->hasIndex($idempotencyLeaseIndex)) {
            $database->executeStatement(sprintf(
                'CREATE INDEX %s ON %s (state, locked_until)',
                $database->quoteSingleIdentifier($idempotencyLeaseIndex),
                $this->tables->quoted('idempotency'),
            ));
        }

        $exists = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE job_type = ?',
            $this->tables->quoted('schedules'),
        ), ['system.idempotency.purge']);
        if ($exists !== false) {
            if (is_string($exists) && $exists !== '') {
                $this->ensureScheduleOwnership($database, $exists);
            }
            return;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $database->insert($this->tables->raw('schedules'), [
            'id' => '00000000-0000-7000-8000-000000000802',
            'name' => 'Purge expired idempotency records',
            'cron_expression' => '17 * * * *',
            'timezone' => 'UTC',
            'queue' => 'default',
            'job_type' => 'system.idempotency.purge',
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
        ], [
            'payload' => Types::JSON,
            'enabled' => Types::BOOLEAN,
            'next_run_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->ensureScheduleOwnership($database, '00000000-0000-7000-8000-000000000802');
    }

    private function ensureScheduleOwnership(Connection $database, string $scheduleId): void
    {
        if (!$database->createSchemaManager()->tablesExist([$this->tables->raw('resource_site_ownership')])) {
            return;
        }
        $exists = $database->fetchOne(sprintf(
            'SELECT 1 FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), ['schedule', $scheduleId]);
        if ($exists !== false) {
            return;
        }
        $database->insert($this->tables->raw('resource_site_ownership'), [
            'resource_type' => 'schedule',
            'resource_id' => $scheduleId,
            'site_identifier' => 'default',
        ]);
    }
}
