<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/** Reconciles authorization state introduced after the immutable job-recovery migration. */
final readonly class AuthorizationRecoveryIntegrationMigration implements Migration
{
    public const ID = '20260805035000_authorization_recovery_integration';

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
            throw new RuntimeException('The authorization recovery migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $idempotency = $after->getTable($this->tables->raw('idempotency'));
        $idempotency->modifyColumn('owner_token', ['length' => 64, 'notnull' => false]);
        $idempotency->modifyColumn('lease_owner', ['notnull' => false]);
        $idempotency->modifyColumn('lease_expires_at', ['notnull' => false]);
        $after->getTable($this->tables->raw('users'))->modifyColumn('security_epoch', [
            'type' => Type::getType(Types::BIGINT),
        ]);
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }

        $scheduleId = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE job_type = ?',
            $this->tables->quoted('schedules'),
        ), ['system.idempotency.purge']);
        if (!is_string($scheduleId) || $scheduleId === '') {
            throw new RuntimeException('The idempotency purge schedule is missing after job recovery.');
        }
        $owned = $database->fetchOne(sprintf(
            'SELECT 1 FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), ['schedule', $scheduleId]);
        if ($owned !== false) {
            return;
        }
        $database->insert($this->tables->raw('resource_site_ownership'), [
            'resource_type' => 'schedule',
            'resource_id' => $scheduleId,
            'site_identifier' => 'default',
        ]);
    }
}
