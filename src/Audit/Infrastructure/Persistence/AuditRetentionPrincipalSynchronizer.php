<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Audit\Application\AuditRetentionPrincipalAssignment;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Makes the database name the configured audit retention login after each schema migration.
 *
 * `database:migrate` runs with the schema-owning identity, which is the only identity allowed to replace
 * the principal routine. When `DB_AUDIT_RETENTION_USER` is configured for that run, the routine is brought
 * into line with it; when it is not, an existing assignment is left exactly as an operator made it.
 *
 * @since  2.0.0
 */
final readonly class AuditRetentionPrincipalSynchronizer implements AuditRetentionPrincipalAssignment
{
    /**
     * Bind the synchronizer to the migration connection and the configured retention login.
     *
     * @param  Connection  $database   Connection holding schema privileges.
     * @param  TableNames  $tables     Prefix-aware physical names.
     * @param  string      $principal  Configured retention login, or empty when none is configured.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private string $principal,
    ) {
    }

    /**
     * Assign the configured principal when the database names a different one.
     *
     * @return  bool  True when the assignment changed.
     *
     * @throws  InvalidArgumentException  When the configured login is not a plain database login name.
     * @throws  RuntimeException  When the retention authority guards are not installed.
     * @throws  \Doctrine\DBAL\Exception  When the server refuses to replace the routine.
     *
     * @since   2.0.0
     */
    public function synchronize(): bool
    {
        if ($this->principal === '') {
            return false;
        }
        if (!AuditRetentionAuthority::installed($this->database, $this->tables)) {
            throw new RuntimeException(
                'The audit retention principal cannot be assigned before its guards are installed.',
            );
        }
        if (AuditRetentionAuthority::principal($this->database, $this->tables) === $this->principal) {
            return false;
        }
        AuditRetentionAuthority::assign($this->database, $this->tables, $this->principal);

        return true;
    }
}
