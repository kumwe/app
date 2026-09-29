<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditAppendOnlyGuard;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditRetentionGuard;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Prevents runtime DML credentials from removing audit evidence and its retention claims together.
 *
 * @since  2.0.0
 */
final readonly class AuditRetentionEvidenceMigration implements RepeatableMigration
{
    /**
     * Immutable identifier ordering this change after the original audit migration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260929120000_audit_retention_evidence';

    /**
     * Bind the migration to the installation's physical table names.
     *
     * @param  TableNames  $tables  Prefix-aware physical table names.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the forward-only migration identity.
     *
     * @return  string  Stable migration ordering key.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Fingerprint the migration bytes before an installation records them.
     *
     * @return  string  Immutable SHA-256 migration fingerprint.
     *
     * @throws  RuntimeException  When this migration cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The audit retention evidence migration checksum cannot be read.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Install immutable ledger and event deletion coverage guards idempotently.
     *
     * @param   Connection  $database  Connection holding migration privileges.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the required guards cannot be installed.
     * @throws  RuntimeException  When the original append-only guards are unavailable.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        if (!AuditAppendOnlyGuard::install($database, $this->tables)->installed()) {
            throw new RuntimeException('Audit retention evidence requires installed append-only guards.');
        }
        AuditRetentionGuard::install($database, $this->tables);
    }
}
