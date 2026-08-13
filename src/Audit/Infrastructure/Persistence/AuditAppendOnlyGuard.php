<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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
 * This is evidence against mistakes and casual tampering, not against a database superuser: an account
 * that may drop triggers can remove them. `docs/operations/monitoring.md` therefore pairs this control
 * with least-privilege account guidance, and the anchor ledger keeps removals evident regardless.
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
     * @param   Connection  $database  Connection the audit table lives on.
     * @param   TableNames  $tables    Resolver for prefixed physical table and trigger names.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the platform cannot enforce an append-only audit trail.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the trigger definition.
     *
     * @since   2.0.0
     */
    public static function install(Connection $database, TableNames $tables): void
    {
        $platform = $database->getDatabasePlatform();
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
        if ($platform instanceof SQLitePlatform) {
            foreach ([[$update, 'UPDATE'], [$delete, 'DELETE']] as [$name, $operation]) {
                if (self::exists($database, $tables, $name)) {
                    continue;
                }
                $database->executeStatement(self::sqliteTrigger($database, $tables, $name, $operation));
            }

            return;
        }

        throw new RuntimeException('The database platform cannot enforce an append-only audit trail.');
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
     * @template T
     *
     * @param   Connection     $database   Connection the guarded delete runs on.
     * @param   TableNames     $tables     Resolver for prefixed physical table and trigger names.
     * @param   callable(): T  $operation  Guarded deletion work to perform inside the open window.
     *
     * @return  T  Whatever the operation returned.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects opening or closing the window.
     *
     * @since   2.0.0
     */
    public static function withPruneAllowed(Connection $database, TableNames $tables, callable $operation): mixed
    {
        $platform = $database->getDatabasePlatform();
        $delete = $tables->raw('audit_append_only_delete');
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
        if ($platform instanceof SQLitePlatform) {
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

        throw new RuntimeException('The database platform cannot open the audit retention window.');
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
