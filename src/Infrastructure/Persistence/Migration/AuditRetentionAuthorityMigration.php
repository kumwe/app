<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditEnforcementRefusal;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditRetentionAuthority;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;
use Throwable;

/**
 * Restricts audit deletion and retention marks to a separately assigned database principal.
 *
 * A fresh installation names no retention principal, so after this migration no session may delete audit
 * rows until an operator assigns one with the migration identity. A server that refuses the trigger or
 * routine privilege is left without the boundary; verification reports that state instead of an intact
 * trail, as it does for the earlier guards.
 *
 * @since  2.0.0
 */
final readonly class AuditRetentionAuthorityMigration implements RepeatableMigration
{
    /**
     * Immutable identifier ordering this change after the retention evidence guards.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260930110000_audit_retention_authority';

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
            throw new RuntimeException('The audit retention authority migration checksum cannot be read.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Install the principal routine and the authority triggers idempotently.
     *
     * @param   Connection  $database  Connection holding migration privileges.
     *
     * @return  void
     *
     * @throws  Throwable  When installation fails for any reason other than a recognized privilege refusal.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        try {
            AuditRetentionAuthority::install($database, $this->tables);
        } catch (Throwable $error) {
            if (!AuditEnforcementRefusal::matches($error)) {
                throw $error;
            }
        }
    }
}
