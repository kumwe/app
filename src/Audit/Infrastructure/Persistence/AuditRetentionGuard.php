<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Keeps retention claims immutable and refuses deletion without a durable claim covering the event.
 *
 * A session variable is not an authority boundary: anyone holding the database credentials can set it.
 * These companion triggers require deletion to leave an immutable prune claim. The verifier checks that
 * claim against private filesystem evidence, which a database-only principal cannot manufacture. Accounts
 * with DDL, trigger ownership, or filesystem privileges remain outside this DML boundary.
 *
 * @since  2.0.0
 */
final class AuditRetentionGuard
{
    /**
     * Install the additional guards without rewriting a released migration or an existing trigger.
     *
     * @param   Connection  $database  Connection holding migration privileges.
     * @param   TableNames  $tables    Prefix-aware physical table names.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the database platform cannot protect retention evidence.
     * @throws  \Doctrine\DBAL\Exception  When required guards cannot be installed.
     *
     * @since   2.0.0
     */
    public static function install(Connection $database, TableNames $tables): void
    {
        $platform = $database->getDatabasePlatform();
        $anchors = $tables->quoted('audit_anchors');
        $coverage = sprintf(
            "EXISTS (SELECT 1 FROM %s WHERE kind = 'prune' AND from_position <= OLD.position "
            . 'AND to_position >= OLD.position AND archive_sha256 IS NOT NULL)',
            $anchors,
        );
        foreach (self::definitions($database) as [$name, $table, $operation]) {
            if (self::exists($database, $tables, $name, $table)) {
                continue;
            }
            $trigger = $database->quoteSingleIdentifier($tables->raw($name));
            $target = $tables->quoted($table);
            $condition = $table === 'audit_events' && $operation === 'DELETE' ? 'NOT ' . $coverage : '1 = 1';
            if ($platform instanceof AbstractMySQLPlatform) {
                $sql = sprintf(
                    "CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW BEGIN IF %s THEN "
                    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s'; END IF; END",
                    $trigger,
                    $operation,
                    $target,
                    $condition,
                    AuditAppendOnlyGuard::MESSAGE,
                );
            } elseif ($platform instanceof PostgreSQLPlatform) {
                $function = $database->quoteSingleIdentifier($tables->raw($name . '_fn'));
                $database->executeStatement(sprintf(
                    'CREATE OR REPLACE FUNCTION %s() RETURNS trigger AS $kumwe$ BEGIN '
                    . 'IF %s THEN RAISE EXCEPTION \'%s\'; END IF; RETURN OLD; END; $kumwe$ LANGUAGE plpgsql',
                    $function,
                    $condition,
                    AuditAppendOnlyGuard::MESSAGE,
                ));
                $sql = sprintf(
                    'CREATE TRIGGER %s BEFORE %s ON %s FOR EACH %s EXECUTE FUNCTION %s()',
                    $trigger,
                    $operation,
                    $target,
                    $operation === 'TRUNCATE' ? 'STATEMENT' : 'ROW',
                    $function,
                );
            } elseif ($platform instanceof SQLitePlatform) {
                $sql = sprintf(
                    'CREATE TRIGGER %s BEFORE %s ON %s WHEN %s BEGIN SELECT RAISE(ABORT, %s); END',
                    $trigger,
                    $operation,
                    $target,
                    $condition,
                    $database->quote(AuditAppendOnlyGuard::MESSAGE),
                );
            } else {
                throw new RuntimeException('The database platform cannot protect audit retention evidence.');
            }
            $database->executeStatement($sql);
        }
    }

    /**
     * Check every required retention guard using the current database catalog.
     *
     * @param   Connection  $database  Connection whose enforcement is being checked.
     * @param   TableNames  $tables    Prefix-aware physical table names.
     *
     * @return  bool  Whether all immutable-ledger and deletion-coverage guards are enabled.
     *
     * @since   2.0.0
     */
    public static function installed(Connection $database, TableNames $tables): bool
    {
        foreach (self::definitions($database) as [$name, $table]) {
            if (!self::exists($database, $tables, $name, $table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Enumerate the statements each supported database must refuse or constrain.
     *
     * @param   Connection  $database  Connection identifying the driver.
     *
     * @return  list<array{string, string, string}>  Trigger name, table name and statement kind.
     *
     * @since   2.0.0
     */
    private static function definitions(Connection $database): array
    {
        $definitions = [
            ['audit_retention_delete', 'audit_events', 'DELETE'],
            ['audit_ledger_update', 'audit_anchors', 'UPDATE'],
            ['audit_ledger_delete', 'audit_anchors', 'DELETE'],
        ];
        if ($database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $definitions[] = ['audit_retention_truncate', 'audit_events', 'TRUNCATE'];
            $definitions[] = ['audit_ledger_truncate', 'audit_anchors', 'TRUNCATE'];
        }

        return $definitions;
    }

    /**
     * Look up a trigger on its expected table, including its enabled state on PostgreSQL.
     *
     * @param   Connection  $database  Connection to inspect.
     * @param   TableNames  $tables    Prefix-aware physical names.
     * @param   string      $name      Logical trigger name.
     * @param   string      $table     Logical table name.
     *
     * @return  bool  Whether the expected enabled trigger exists.
     *
     * @since   2.0.0
     */
    private static function exists(Connection $database, TableNames $tables, string $name, string $table): bool
    {
        $platform = $database->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            return $database->fetchOne(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() '
                . 'AND TRIGGER_NAME = ? AND EVENT_OBJECT_TABLE = ?',
                [$tables->raw($name), $tables->raw($table)],
            ) !== false;
        }
        if ($platform instanceof PostgreSQLPlatform) {
            return $database->fetchOne(
                "SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgenabled IN ('O', 'A') "
                . 'AND tgname = ? AND tgrelid = to_regclass(?)',
                [$tables->raw($name), $tables->raw($table)],
            ) !== false;
        }
        if ($platform instanceof SQLitePlatform) {
            return $database->fetchOne(
                "SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = ? AND tbl_name = ?",
                [$tables->raw($name), $tables->raw($table)],
            ) !== false;
        }

        return false;
    }
}
