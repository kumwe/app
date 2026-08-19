<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/** Makes completed idempotency records compatible with released ownership leases. */
final readonly class IdempotencyLeaseNullabilityMigration implements Migration
{
    public const ID = '20260805040000_release_idempotency_leases';

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
            throw new RuntimeException('The idempotency lease migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $idempotency = $after->getTable($this->tables->raw('idempotency'));

        foreach (['lease_owner', 'lease_expires_at'] as $columnName) {
            if (!$idempotency->hasColumn($columnName)) {
                throw new RuntimeException(sprintf(
                    'The idempotency column "%s" must exist before lease compatibility is applied.',
                    $columnName,
                ));
            }

            $idempotency->getColumn($columnName)->setNotnull(false);
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }
}
