<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Kumwe\CMS\Audit\Domain\AuditEnforcementState;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;
use Throwable;

/**
 * Database-level append-only enforcement for `audit_events`, and the one sanctioned way past it.
 *
 * Application discipline alone cannot make a trail append-only: the connection that writes it can also
 * rewrite it. This installs `BEFORE UPDATE` and `BEFORE DELETE` triggers that abort the statement on
 * each supported driver, so an accidental `UPDATE`, a stray `DELETE`, or any future code path that
 * forgets the rule fails at the database rather than silently succeeding. `UPDATE` has no exemption at
 * all. `DELETE` is refused unless the session has opened the retention window through
 * `withPruneAllowed()`, which the retention service does only after the range has been archived and
 * anchored — the flag is session-scoped (a MySQL user variable, a PostgreSQL `SET LOCAL`) so it can
 * never leak into another connection, and on SQLite, whose triggers cannot read session state, the
 * trigger is dropped and recreated inside the same transaction instead.
 *
 * Installation is best-effort by design, because the privilege it needs is one a managed database
 * routinely withholds — a MySQL with binary logging enabled and no `SUPER` refuses `CREATE TRIGGER`
 * outright, which describes Amazon RDS, Cloud SQL and Azure Database for MySQL as they ship. Demanding
 * the privilege would make Kumwe uninstallable there, so `install()` reports the refusal as a state
 * instead of raising it, and only for the exact codes `AuditEnforcementRefusal` recognises; every other
 * failure still aborts the migration. What is lost when enforcement is unavailable is *prevention*, not
 * evidence: digests, witness links, the anchor ledger and `audit:verify` are untouched, and the
 * verification report names the degraded state so nobody reads a clean chain as a guarded one.
 *
 * Even when installed this is evidence against mistakes and casual tampering, not against a database
 * superuser: an account that may drop triggers can remove them. `docs/operations/monitoring.md`
 * therefore pairs this control with least-privilege account guidance, and the anchor ledger keeps
 * removals evident regardless.
 *
 * @since  2.0.0
 */
final class AuditAppendOnlyGuard
{
    /**
     * Message every driver's guard raises when a write is refused.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string MESSAGE = 'The Kumwe audit trail is append-only.';

    /**
     * Session flag name the guarded delete path opens the retention window with.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string FLAG = 'kumwe_audit_prune';

    /**
     * Install the append-only triggers, doing nothing when they are already present.
     *
     * The return value is the state the server was left in, and it is read back from the catalog rather
     * than inferred from whether the statements threw, so a partial install is reported as unavailable
     * rather than as success. A privilege refusal is absorbed; anything else propagates.
     *
     * @param   Connection  $database  Connection the audit table lives on.
     * @param   TableNames  $tables    Resolver for prefixed physical table and trigger names.
     *
     * @return  AuditEnforcementState  `Active` when the guards are in place afterwards, `NotInstalled`
     *          when this server refused the privilege they need.
     *
     * @throws  RuntimeException  When the platform cannot enforce an append-only audit trail.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the trigger definition for any reason
     *          other than a privilege or capability refusal.
     *
     * @since   2.0.0
     */
    public static function install(Connection $database, TableNames $tables): AuditEnforcementState
    {
        $platform = $database->getDatabasePlatform();
        if (
            !$platform instanceof AbstractMySQLPlatform
            && !$platform instanceof PostgreSQLPlatform
            && !$platform instanceof SQLitePlatform
        ) {
            throw new RuntimeException('The database platform cannot enforce an append-only audit trail.');
        }
        try {
            self::createGuards($database, $tables, $platform);
        } catch (Throwable $error) {
            if (!AuditEnforcementRefusal::matches($error)) {
                throw $error;
            }
        }

        return self::state($database, $tables);
    }

    /**
     * Issue this platform's guard definitions, skipping whichever are already present.
     *
     * @param   Connection        $database  Connection the audit table lives on.
     * @param   TableNames        $tables    Resolver for prefixed physical table and trigger names.
     * @param   AbstractPlatform  $platform  Platform `install()` has already checked is supported.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a trigger definition.
     *
     * @since   2.0.0
     */
    private static function createGuards(
        Connection $database,
        TableNames $tables,
        AbstractPlatform $platform,
    ): void {
        $events = $tables->quoted('audit_events');
        $update = $tables->raw('audit_append_only_update');
        $delete = $tables->raw('audit_append_only_delete');
        if ($platform instanceof AbstractMySQLPlatform) {
            if (!self::exists($database, $tables, $update)) {
                $database->executeStatement(sprintf(
                    'CREATE TRIGGER %s BEFORE UPDATE ON %s FOR EACH ROW '
                    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s'",
                    $database->quoteSingleIdentifier($update),
                    $events,
                    self::MESSAGE,
                ));
            }
            if (!self::exists($database, $tables, $delete)) {
                $database->executeStatement(sprintf(
                    'CREATE TRIGGER %s BEFORE DELETE ON %s FOR EACH ROW BEGIN '
                    . 'IF COALESCE(@%s, 0) <> 1 THEN '
                    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s'; END IF; END",
                    $database->quoteSingleIdentifier($delete),
                    $events,
                    self::FLAG,
                    self::MESSAGE,
                ));
            }

            return;
        }
        if ($platform instanceof PostgreSQLPlatform) {
            $function = $tables->raw('audit_append_only');
            $database->executeStatement(sprintf(
                'CREATE OR REPLACE FUNCTION %s() RETURNS trigger AS $kumwe$ BEGIN '
                . "IF TG_OP = 'DELETE' AND COALESCE(current_setting('%s.enabled', true), '') = '1' "
                . 'THEN RETURN OLD; END IF; '
                . 'RAISE EXCEPTION \'%s\'; END; $kumwe$ LANGUAGE plpgsql',
                $database->quoteSingleIdentifier($function),
                self::FLAG,
                self::MESSAGE,
            ));
            if (!self::exists($database, $tables, $update)) {
                $database->executeStatement(sprintf(
                    'CREATE TRIGGER %s BEFORE UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
                    $database->quoteSingleIdentifier($update),
                    $events,
                    $database->quoteSingleIdentifier($function),
                ));
            }

            return;
        }
        foreach ([[$update, 'UPDATE'], [$delete, 'DELETE']] as [$name, $operation]) {
            if (self::exists($database, $tables, $name)) {
                continue;
            }
            $database->executeStatement(self::sqliteTrigger($database, $tables, $name, $operation));
        }
    }

    /**
     * Observe from the server's own catalog whether append-only enforcement is in force right now.
     *
     * This is deliberately a question put to the database rather than a flag the migration wrote down.
     * A stored claim would survive a dump and restore onto a server that never accepted the triggers,
     * would go stale the moment a DBA granted the missing privilege, and would keep saying "enforced"
     * after somebody dropped them. The catalog lookup is cheap and cannot be wrong.
     *
     * @param   Connection  $database  Connection the audit table lives on.
     * @param   TableNames  $tables    Resolver for prefixed physical table and trigger names.
     *
     * @return  AuditEnforcementState  What this server is actually enforcing at the moment of the call.
     *
     * @since   2.0.0
     */
    public static function state(Connection $database, TableNames $tables): AuditEnforcementState
    {
        return self::installed($database, $tables)
            ? AuditEnforcementState::Active
            : AuditEnforcementState::NotInstalled;
    }

    /**
     * Report whether every guard this platform needs is present.
     *
     * @param   Connection  $database  Connection the audit table lives on.
     * @param   TableNames  $tables    Resolver for prefixed physical table and trigger names.
     *
     * @return  bool  True when the platform's append-only triggers are installed.
     *
     * @since   2.0.0
     */
    public static function installed(Connection $database, TableNames $tables): bool
    {
        $update = self::exists($database, $tables, $tables->raw('audit_append_only_update'));
        if ($database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return $update;
        }

        return $update && self::exists($database, $tables, $tables->raw('audit_append_only_delete'));
    }

    /**
     * Run one operation with the retention window open, closing it again whatever the outcome.
     *
     * The window is opened as narrowly as the platform allows: a session variable on MySQL, a
     * transaction-local setting on PostgreSQL, and a trigger dropped inside the caller's transaction on
     * SQLite. Callers must already hold a transaction, so a failure discards both the deletions and, on
     * SQLite, the trigger removal.
     *
     * When no delete guard is installed there is no window to open, and opening one anyway would be
     * worse than pointless: the SQLite path closes by *creating* the trigger, so a server that never
     * accepted enforcement would silently acquire half of it as a side effect of a retention pass. The
     * guard is therefore looked up first and the operation simply runs when enforcement is absent.
     *
     * @template T
     *
     * @param   Connection     $database   Connection the guarded delete runs on.
     * @param   TableNames     $tables     Resolver for prefixed physical table and trigger names.
     * @param   callable(): T  $operation  Guarded deletion work to perform inside the open window.
     *
     * @return  T  Whatever the operation returned.
     *
     * @throws  RuntimeException  When the platform cannot open the audit retention window.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects opening or closing the window.
     *
     * @since   2.0.0
     */
    public static function withPruneAllowed(Connection $database, TableNames $tables, callable $operation): mixed
    {
        $platform = $database->getDatabasePlatform();
        $delete = $tables->raw('audit_append_only_delete');
        if (
            !$platform instanceof AbstractMySQLPlatform
            && !$platform instanceof PostgreSQLPlatform
            && !$platform instanceof SQLitePlatform
        ) {
            throw new RuntimeException('The database platform cannot open the audit retention window.');
        }
        if (!self::deleteGuarded($database, $tables)) {
            return $operation();
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            $database->executeStatement(sprintf('SET @%s = 1', self::FLAG));
            try {
                return $operation();
            } finally {
                $database->executeStatement(sprintf('SET @%s = NULL', self::FLAG));
            }
        }
        if ($platform instanceof PostgreSQLPlatform) {
            $database->executeStatement(sprintf("SET LOCAL %s.enabled = '1'", self::FLAG));
            try {
                return $operation();
            } finally {
                $database->executeStatement(sprintf("SET LOCAL %s.enabled = '0'", self::FLAG));
            }
        }
        $database->executeStatement(sprintf(
            'DROP TRIGGER IF EXISTS %s',
            $database->quoteSingleIdentifier($delete),
        ));
        try {
            return $operation();
        } finally {
            $database->executeStatement(self::sqliteTrigger($database, $tables, $delete, 'DELETE'));
        }
    }

    /**
     * Report whether a guard is present that would refuse the deletion the retention window sanctions.
     *
     * PostgreSQL enforces both statement kinds through the one `BEFORE UPDATE OR DELETE` trigger, so
     * the delete guard there is the same object the update guard is; MySQL and SQLite carry a separate
     * trigger per statement kind.
     *
     * @param   Connection  $database  Connection the guarded delete runs on.
     * @param   TableNames  $tables    Resolver for prefixed physical table and trigger names.
     *
     * @return  bool  True when a delete would currently be refused without an open window.
     *
     * @since   2.0.0
     */
    private static function deleteGuarded(Connection $database, TableNames $tables): bool
    {
        $name = $database->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'audit_append_only_update'
            : 'audit_append_only_delete';

        return self::exists($database, $tables, $tables->raw($name));
    }

    /**
     * Compose one SQLite abort trigger definition.
     *
     * @param   Connection  $database   Connection whose platform supplies identifier quoting.
     * @param   TableNames  $tables     Resolver for prefixed physical table names.
     * @param   string      $name       Physical trigger name.
     * @param   string      $operation  Statement kind the trigger refuses, `UPDATE` or `DELETE`.
     *
     * @return  string  Complete `CREATE TRIGGER` statement.
     *
     * @since   2.0.0
     */
    private static function sqliteTrigger(
        Connection $database,
        TableNames $tables,
        string $name,
        string $operation,
    ): string {
        return sprintf(
            'CREATE TRIGGER %s BEFORE %s ON %s BEGIN SELECT RAISE(ABORT, %s); END',
            $database->quoteSingleIdentifier($name),
            $operation,
            $tables->quoted('audit_events'),
            $database->quote(self::MESSAGE),
        );
    }

    /**
     * Report whether one named trigger exists on this platform.
     *
     * @param   Connection  $database  Connection to interrogate.
     * @param   TableNames  $tables    Resolver for prefixed physical table names.
     * @param   string      $name      Physical trigger name to look for.
     *
     * @return  bool  True when the catalog holds a trigger under that name.
     *
     * @since   2.0.0
     */
    private static function exists(Connection $database, TableNames $tables, string $name): bool
    {
        $platform = $database->getDatabasePlatform();
        try {
            if ($platform instanceof AbstractMySQLPlatform) {
                return $database->fetchOne(
                    'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
                    . 'WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                    [$name],
                ) !== false;
            }
            if ($platform instanceof PostgreSQLPlatform) {
                return $database->fetchOne(
                    'SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgname = ? '
                    . 'AND tgrelid = to_regclass(?)',
                    [$name, $tables->raw('audit_events')],
                ) !== false;
            }

            return $database->fetchOne(
                "SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = ?",
                [$name],
            ) !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
