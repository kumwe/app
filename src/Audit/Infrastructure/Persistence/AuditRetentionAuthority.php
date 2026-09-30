<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use Kumwe\App\Audit\Application\AuditRetentionAuthorityState;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Binds audit deletion and retention marks to a database principal the runtime cannot impersonate.
 *
 * The earlier guards open the retention window with a session variable, which any holder of the runtime
 * credentials can set, and accept a prune mark any `INSERT` can write. This boundary adds what a
 * data-manipulation statement cannot supply: the authenticated identity of the session. Two triggers
 * refuse a `DELETE` on `audit_events` and any non-`anchor` row appended to `audit_anchors` unless the
 * session is the retention principal. That principal is named by the routine `audit_retention_principal()`,
 * which only a schema owner can replace, so the runtime account can neither grant itself the authority
 * nor rewrite who holds it. A fresh installation names nobody, which refuses every deletion.
 *
 * The identity is the login name the server authenticated: PostgreSQL's `session_user`, which `SET ROLE`
 * does not change, and the user part of MySQL/MariaDB's `USER()`. The triggers compare nothing held in
 * a table, so a session-scoped temporary table cannot shadow the decision. MySQL and MariaDB installations
 * must not carry anonymous accounts, whose client-supplied name `USER()` would echo. Accounts that may
 * alter triggers or routines remain outside this boundary. SQLite, a test-only engine, has no principals.
 *
 * @since  2.0.0
 */
final class AuditRetentionAuthority
{
    /**
     * Message the authority triggers raise when a session without retention authority removes evidence.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string MESSAGE = 'Audit evidence may be removed only by the audit retention principal.';

    /**
     * Logical name of the routine that names the retention principal.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PRINCIPAL = 'audit_retention_principal';

    /**
     * Logical name of the MySQL/MariaDB definer-rights routine that reports which table a trigger guards.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CATALOG = 'audit_guard_catalog';

    /**
     * Logical name of the PostgreSQL trigger function both authority triggers execute.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string FUNCTION = 'audit_retention_authority_fn';

    /**
     * Trigger name, guarded table and statement kind of each authority trigger.
     *
     * @var    list<array{string, string, string}>
     * @since  2.0.0
     */
    private const array TRIGGERS = [
        ['audit_retention_authority', 'audit_events', 'DELETE'],
        ['audit_prune_authority', 'audit_anchors', 'INSERT'],
    ];

    /**
     * Install the principal routine and the authority triggers, leaving anything already present untouched.
     *
     * An existing principal routine is never replaced here, because it may already name the operator's
     * retention principal; `assign()` is the only path that changes it.
     *
     * @param   Connection  $database  Connection holding schema privileges.
     * @param   TableNames  $tables    Prefix-aware physical names.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the platform cannot bind retention to a database principal.
     * @throws  \Doctrine\DBAL\Exception  When the server rejects a definition.
     *
     * @since   2.0.0
     */
    public static function install(Connection $database, TableNames $tables): void
    {
        $platform = $database->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            return;
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            if (!self::routineExists($database, $tables)) {
                $database->executeStatement(self::mysqlPrincipalRoutine($database, $tables, null));
            }
            if (!self::routineExists($database, $tables, self::CATALOG)) {
                $database->executeStatement(sprintf(
                    'CREATE FUNCTION %s(kumwe_trigger VARCHAR(128)) RETURNS VARCHAR(128) CHARSET utf8mb4 '
                    . 'READS SQL DATA SQL SECURITY DEFINER RETURN (SELECT EVENT_OBJECT_TABLE FROM '
                    . 'information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() '
                    . 'AND CAST(TRIGGER_NAME AS BINARY) = CAST(kumwe_trigger AS BINARY) LIMIT 1)',
                    $database->quoteSingleIdentifier($tables->raw(self::CATALOG)),
                ));
            }
            $denied = sprintf(
                "NOT COALESCE(CONVERT(SUBSTRING_INDEX(USER(), '@', 1) USING utf8mb4) COLLATE utf8mb4_bin = %s(), "
                . 'FALSE)',
                $database->quoteSingleIdentifier($tables->raw(self::PRINCIPAL)),
            );
            foreach (self::TRIGGERS as [$name, $table, $operation]) {
                if (AuditRetentionGuard::exists($database, $tables, $name, $table)) {
                    continue;
                }
                $database->executeStatement(sprintf(
                    'CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW BEGIN IF %s%s THEN '
                    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s'; END IF; END",
                    $database->quoteSingleIdentifier($tables->raw($name)),
                    $operation,
                    $tables->quoted($table),
                    $operation === 'INSERT' ? "NEW.kind <> 'anchor' AND " : '',
                    $denied,
                    self::MESSAGE,
                ));
            }

            return;
        }
        if (!$platform instanceof PostgreSQLPlatform) {
            throw new RuntimeException('The database platform cannot bind audit retention to a principal.');
        }
        if (!self::routineExists($database, $tables)) {
            $database->executeStatement(self::postgresPrincipalRoutine($database, $tables, null, false));
        }
        $database->executeStatement(sprintf(
            'CREATE OR REPLACE FUNCTION %s() RETURNS trigger LANGUAGE plpgsql '
            . 'SET search_path = pg_catalog, pg_temp AS $kumwe$ BEGIN '
            . "IF TG_OP = 'INSERT' THEN IF NEW.kind = 'anchor' THEN RETURN NEW; END IF; END IF; "
            . 'IF NOT COALESCE(session_user::text = %s(), false) THEN RAISE EXCEPTION %s; END IF; '
            . "IF TG_OP = 'INSERT' THEN RETURN NEW; END IF; RETURN OLD; END; \$kumwe\$",
            self::qualified($database, $tables, self::FUNCTION),
            self::qualified($database, $tables, self::PRINCIPAL),
            $database->quote(self::MESSAGE),
        ));
        foreach (self::TRIGGERS as [$name, $table, $operation]) {
            if (AuditRetentionGuard::exists($database, $tables, $name, $table)) {
                continue;
            }
            $database->executeStatement(sprintf(
                'CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
                $database->quoteSingleIdentifier($tables->raw($name)),
                $operation,
                $tables->quoted($table),
                self::qualified($database, $tables, self::FUNCTION),
            ));
        }
    }

    /**
     * Name the database login that alone may remove archived audit evidence.
     *
     * Run with the schema-owning migration identity: replacing the routine is exactly the privilege the
     * runtime principal must not hold. Passing null withdraws the authority from everybody.
     *
     * @param   Connection  $database   Connection holding schema privileges.
     * @param   TableNames  $tables     Prefix-aware physical names.
     * @param   ?string     $principal  Login name of the retention principal, or null to name nobody.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the principal is not a plain database login name.
     * @throws  RuntimeException  When the platform cannot bind retention to a database principal.
     * @throws  \Doctrine\DBAL\Exception  When the server refuses to replace the routine.
     *
     * @since   2.0.0
     */
    public static function assign(Connection $database, TableNames $tables, ?string $principal): void
    {
        if ($principal !== null && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.$-]{0,62}$/D', $principal) !== 1) {
            throw new InvalidArgumentException('The audit retention principal must be a plain database login name.');
        }
        $platform = $database->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            return;
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            $database->executeStatement(sprintf(
                'DROP FUNCTION IF EXISTS %s',
                $database->quoteSingleIdentifier($tables->raw(self::PRINCIPAL)),
            ));
            $database->executeStatement(self::mysqlPrincipalRoutine($database, $tables, $principal));

            return;
        }
        if (!$platform instanceof PostgreSQLPlatform) {
            throw new RuntimeException('The database platform cannot bind audit retention to a principal.');
        }
        $database->executeStatement(self::postgresPrincipalRoutine($database, $tables, $principal, true));
    }

    /**
     * Read the retention principal the database currently names.
     *
     * @param   Connection  $database  Connection to ask.
     * @param   TableNames  $tables    Prefix-aware physical names.
     *
     * @return  ?string  The assigned login name, or null when nobody is assigned or the routine is absent.
     *
     * @throws  \Doctrine\DBAL\Exception  When the server cannot evaluate the routine.
     *
     * @since   2.0.0
     */
    public static function principal(Connection $database, TableNames $tables): ?string
    {
        if ($database->getDatabasePlatform() instanceof SQLitePlatform || !self::routineExists($database, $tables)) {
            return null;
        }
        $name = $database->fetchOne(sprintf('SELECT %s()', self::routine($database, $tables)));

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Report whether every authority trigger and the principal routine are in force.
     *
     * @param   Connection  $database  Connection whose catalog is inspected.
     * @param   TableNames  $tables    Prefix-aware physical names.
     *
     * @return  bool  True when the boundary is installed, and always on the principal-less SQLite engine.
     *
     * @throws  \Doctrine\DBAL\Exception  When the catalog cannot be read.
     *
     * @since   2.0.0
     */
    public static function installed(Connection $database, TableNames $tables): bool
    {
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            return true;
        }
        foreach (self::TRIGGERS as [$name, $table]) {
            if (!AuditRetentionGuard::exists($database, $tables, $name, $table)) {
                return false;
            }
        }

        return self::routineExists($database, $tables);
    }

    /**
     * Report whether the database would let this connection's session remove archived evidence.
     *
     * @param   Connection  $database  Connection whose authenticated session is tested.
     * @param   TableNames  $tables    Prefix-aware physical names.
     *
     * @return  bool  True when the session is the assigned retention principal, or on SQLite.
     *
     * @throws  \Doctrine\DBAL\Exception  When the server cannot evaluate the comparison.
     *
     * @since   2.0.0
     */
    public static function sessionAuthorized(Connection $database, TableNames $tables): bool
    {
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            return true;
        }
        $principal = self::principal($database, $tables);

        return $principal !== null && hash_equals($principal, self::sessionLogin($database));
    }

    /**
     * Observe the retention authority posture as it applies to one runtime connection.
     *
     * @param   Connection  $runtime  Ordinary application connection whose separation is being judged.
     * @param   TableNames  $tables   Prefix-aware physical names.
     *
     * @return  AuditRetentionAuthorityState  The posture the database enforces for that session right now.
     *
     * @throws  \Doctrine\DBAL\Exception  When the catalog or the session identity cannot be read.
     *
     * @since   2.0.0
     */
    public static function state(Connection $runtime, TableNames $tables): AuditRetentionAuthorityState
    {
        if ($runtime->getDatabasePlatform() instanceof SQLitePlatform) {
            return AuditRetentionAuthorityState::SinglePrincipal;
        }
        if (!self::installed($runtime, $tables)) {
            return AuditRetentionAuthorityState::NotInstalled;
        }
        $principal = self::principal($runtime, $tables);
        if ($principal === null) {
            return AuditRetentionAuthorityState::Unassigned;
        }
        if (hash_equals($principal, self::sessionLogin($runtime)) || self::superuser($runtime)) {
            return AuditRetentionAuthorityState::NotSeparated;
        }

        return AuditRetentionAuthorityState::Separated;
    }

    /**
     * Read the login name the server authenticated this session as.
     *
     * @param   Connection  $database  Connection whose session is inspected.
     *
     * @return  string  PostgreSQL `session_user`, or the user part of MySQL/MariaDB `USER()`.
     *
     * @throws  \Doctrine\DBAL\Exception  When the server cannot report the session identity.
     *
     * @since   2.0.0
     */
    private static function sessionLogin(Connection $database): string
    {
        $login = $database->fetchOne(
            $database->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? 'SELECT session_user::text'
                : "SELECT SUBSTRING_INDEX(USER(), '@', 1)",
        );

        return is_string($login) ? $login : '';
    }

    /**
     * Report whether a PostgreSQL session is a superuser, which no trigger can constrain.
     *
     * @param   Connection  $database  Connection whose session is inspected.
     *
     * @return  bool  True for a PostgreSQL superuser session; false on MySQL and MariaDB.
     *
     * @throws  \Doctrine\DBAL\Exception  When the role catalog cannot be read.
     *
     * @since   2.0.0
     */
    private static function superuser(Connection $database): bool
    {
        if (!$database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return false;
        }

        return in_array(
            $database->fetchOne('SELECT rolsuper FROM pg_catalog.pg_roles WHERE rolname = session_user'),
            [true, 1, '1', 't', 'true'],
            true,
        );
    }

    /**
     * Report whether a routine exists in this installation's schema.
     *
     * @param   Connection  $database  Connection whose catalog is inspected.
     * @param   TableNames  $tables    Prefix-aware physical names.
     * @param   string      $name      Logical routine name.
     *
     * @return  bool  True when the routine is defined and visible to this session.
     *
     * @throws  \Doctrine\DBAL\Exception  When the catalog cannot be read.
     *
     * @since   2.0.0
     */
    private static function routineExists(
        Connection $database,
        TableNames $tables,
        string $name = self::PRINCIPAL,
    ): bool {
        if ($database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return $database->fetchOne(
                'SELECT p.proname FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n '
                . 'ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.proname = ?',
                [$tables->raw($name)],
            ) !== false;
        }

        return $database->fetchOne(
            'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() '
            . "AND ROUTINE_TYPE = 'FUNCTION' AND ROUTINE_NAME = ?",
            [$tables->raw($name)],
        ) !== false;
    }

    /**
     * Compose the MySQL/MariaDB principal routine.
     *
     * @param   Connection  $database   Connection supplying identifier and literal quoting.
     * @param   TableNames  $tables     Prefix-aware physical names.
     * @param   ?string     $principal  Validated login name, or null for nobody.
     *
     * @return  string  Complete `CREATE FUNCTION` statement.
     *
     * @since   2.0.0
     */
    private static function mysqlPrincipalRoutine(Connection $database, TableNames $tables, ?string $principal): string
    {
        return sprintf(
            'CREATE FUNCTION %s() RETURNS VARCHAR(128) CHARSET utf8mb4 COLLATE utf8mb4_bin '
            . 'DETERMINISTIC NO SQL RETURN %s',
            $database->quoteSingleIdentifier($tables->raw(self::PRINCIPAL)),
            $principal === null ? 'NULL' : $database->quote($principal),
        );
    }

    /**
     * Compose the PostgreSQL principal routine.
     *
     * @param   Connection  $database   Connection supplying identifier and literal quoting.
     * @param   TableNames  $tables     Prefix-aware physical names.
     * @param   ?string     $principal  Validated login name, or null for nobody.
     * @param   bool        $replace    Whether an existing routine is replaced.
     *
     * @return  string  Complete `CREATE FUNCTION` statement.
     *
     * @throws  \Doctrine\DBAL\Exception  When the current schema cannot be read.
     *
     * @since   2.0.0
     */
    private static function postgresPrincipalRoutine(
        Connection $database,
        TableNames $tables,
        ?string $principal,
        bool $replace,
    ): string {
        return sprintf(
            'CREATE %sFUNCTION %s() RETURNS text LANGUAGE sql STABLE SET search_path = pg_catalog, pg_temp '
            . 'AS $kumwe$ SELECT %s::text $kumwe$',
            $replace ? 'OR REPLACE ' : '',
            self::qualified($database, $tables, self::PRINCIPAL),
            $principal === null ? 'NULL' : $database->quote($principal),
        );
    }

    /**
     * Name the principal routine the way a query on this platform must call it.
     *
     * @param   Connection  $database  Connection identifying the platform.
     * @param   TableNames  $tables    Prefix-aware physical names.
     *
     * @return  string  Schema-qualified on PostgreSQL, schema-local on MySQL and MariaDB.
     *
     * @throws  \Doctrine\DBAL\Exception  When the current schema cannot be read.
     *
     * @since   2.0.0
     */
    private static function routine(Connection $database, TableNames $tables): string
    {
        return $database->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? self::qualified($database, $tables, self::PRINCIPAL)
            : $database->quoteSingleIdentifier($tables->raw(self::PRINCIPAL));
    }

    /**
     * Qualify a PostgreSQL routine with the installation's schema, so no `search_path` entry can shadow it.
     *
     * @param   Connection  $database  PostgreSQL connection.
     * @param   TableNames  $tables    Prefix-aware physical names.
     * @param   string      $name      Logical routine name.
     *
     * @return  string  Quoted `schema.routine` name.
     *
     * @throws  RuntimeException  When the session has no current schema.
     * @throws  \Doctrine\DBAL\Exception  When the current schema cannot be read.
     *
     * @since   2.0.0
     */
    private static function qualified(Connection $database, TableNames $tables, string $name): string
    {
        $schema = $database->fetchOne('SELECT current_schema()');
        if (!is_string($schema) || $schema === '') {
            throw new RuntimeException('The audit retention authority requires a current schema.');
        }

        return $database->quoteSingleIdentifier($schema) . '.' . $database->quoteSingleIdentifier($tables->raw($name));
    }
}
