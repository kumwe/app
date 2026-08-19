<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Gives administrator sessions an epoch to be measured against, and step-up credentials a revocation.
 *
 * Two additive columns, both in service of one property: a credential change must be able to retire
 * everything the installation already issued under the old credential. The security epoch on the user
 * row was already that instrument for API tokens, portal sessions and step-up proofs, but the
 * `administrator_sessions` row carried no epoch to compare, so a live administrator cookie outlived a
 * break-glass revocation. `security_epoch` is added here and backfilled from the owning user, which
 * makes every session that predates the migration valid exactly until the next epoch advance — the
 * conservative direction, since the alternative would sign every administrator out on deploy.
 *
 * `step_up_credentials.revocation_reason` mirrors what `api_tokens` already stores beside a revoked
 * credential. An administrative second-factor reset is a takeover-shaped act, so the justification
 * belongs on the row an auditor reads as well as in the audit event, and the row survives audit
 * retention.
 *
 * Every step is guarded by introspection and the backfill is a single idempotent `UPDATE`, so an
 * interrupted attempt on a platform whose DDL commits implicitly may simply be replayed. No step needs
 * a privilege beyond `ALTER TABLE` and `CREATE INDEX` on tables the installation already owns, so this
 * runs unchanged on MariaDB, MySQL with binary logging and no `SUPER`, PostgreSQL and SQLite.
 *
 * @since  2.0.0
 */
final readonly class CredentialLifecycleMigration implements RepeatableMigration
{
    /**
     * Stable migration identity recorded in the schema ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260813020000_credential_lifecycle';

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
            throw new RuntimeException('The credential lifecycle migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Add the session epoch, its lookup index and the step-up revocation reason, then prove them.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a postcondition does not hold once every step has run.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $this->addSessionEpoch($database);
        $this->addSessionUserIndex($database);
        $this->addStepUpRevocationReason($database);
        $this->assertApplied($database);
    }

    /**
     * Add `security_epoch` to administrator sessions and set it from the session's own user.
     *
     * The column arrives with a default of one so the `ALTER` never has to rewrite rows into an
     * impossible state, and the backfill then lifts each existing row to its user's current epoch. A
     * session whose user vanished keeps the default and stops resolving on the next request, which is
     * the same answer the joined `users` row would have produced.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the alter or the backfill.
     *
     * @since   2.0.0
     */
    private function addSessionEpoch(Connection $database): void
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName(
            $this->tables->raw('administrator_sessions'),
        );
        if (!$table->hasColumn('security_epoch')) {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD %s BIGINT NOT NULL DEFAULT 1',
                $this->tables->quoted('administrator_sessions'),
                $database->quoteSingleIdentifier('security_epoch'),
            ));
        }
        $database->executeStatement(sprintf(
            'UPDATE %s SET security_epoch = (SELECT u.security_epoch FROM %s u WHERE u.id = %s.user_id) '
            . 'WHERE EXISTS (SELECT 1 FROM %s u WHERE u.id = %s.user_id AND u.security_epoch <> %s.security_epoch)',
            $this->tables->quoted('administrator_sessions'),
            $this->tables->quoted('users'),
            $this->tables->quoted('administrator_sessions'),
            $this->tables->quoted('users'),
            $this->tables->quoted('administrator_sessions'),
            $this->tables->quoted('administrator_sessions'),
        ));
    }

    /**
     * Index administrator sessions by their owning user so a per-user termination is a keyed read.
     *
     * MySQL and MariaDB create an index for the existing foreign key on `user_id` themselves;
     * PostgreSQL and SQLite do not, and the new terminate-all-sessions operation selects on exactly
     * that column. The index is created only when introspection shows no index already leads with it,
     * which keeps a replay from failing on a name the platform allocated for the constraint.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the index creation.
     *
     * @since   2.0.0
     */
    private function addSessionUserIndex(Connection $database): void
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName(
            $this->tables->raw('administrator_sessions'),
        );
        foreach ($table->getIndexes() as $index) {
            $columns = array_values($index->getIndexedColumns());
            if (($columns[0] ?? null) === null) {
                continue;
            }
            if (strtolower($columns[0]->getColumnName()->toString()) === 'user_id') {
                return;
            }
        }
        $database->executeStatement(sprintf(
            'CREATE INDEX %s ON %s (%s)',
            $database->quoteSingleIdentifier($this->tables->raw('idx_admin_session_user')),
            $this->tables->quoted('administrator_sessions'),
            $database->quoteSingleIdentifier('user_id'),
        ));
    }

    /**
     * Record why a step-up credential was retired, beside the credential rather than only in the trail.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the alter.
     *
     * @since   2.0.0
     */
    private function addStepUpRevocationReason(Connection $database): void
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName(
            $this->tables->raw('step_up_credentials'),
        );
        if ($table->hasColumn('revocation_reason')) {
            return;
        }
        $database->executeStatement(sprintf(
            'ALTER TABLE %s ADD %s VARCHAR(500) DEFAULT NULL',
            $this->tables->quoted('step_up_credentials'),
            $database->quoteSingleIdentifier('revocation_reason'),
        ));
    }

    /**
     * Prove the columns this migration exists to establish are present before it is recorded as applied.
     *
     * @param   Connection  $database  Connection the checks run on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a column this migration adds is missing afterwards.
     *
     * @since   2.0.0
     */
    private function assertApplied(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $sessions = $manager->introspectTableByUnquotedName($this->tables->raw('administrator_sessions'));
        if (!$sessions->hasColumn('security_epoch')) {
            throw new RuntimeException('The administrator session security epoch column is missing.');
        }
        $credentials = $manager->introspectTableByUnquotedName($this->tables->raw('step_up_credentials'));
        if (!$credentials->hasColumn('revocation_reason')) {
            throw new RuntimeException('The step-up credential revocation reason column is missing.');
        }
        $stale = $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s s INNER JOIN %s u ON u.id = s.user_id WHERE s.security_epoch <> u.security_epoch',
            $this->tables->quoted('administrator_sessions'),
            $this->tables->quoted('users'),
        ));
        if (!is_int($stale) && (!is_string($stale) || preg_match('/^[0-9]+$/D', $stale) !== 1)) {
            throw new RuntimeException('The administrator session epoch backfill could not be counted.');
        }
        if ((int) $stale !== 0) {
            throw new RuntimeException('The administrator session epoch backfill left rows behind.');
        }
    }
}
