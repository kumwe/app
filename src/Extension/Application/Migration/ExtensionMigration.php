<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Migration;

use Doctrine\DBAL\Connection;

/**
 * A forward extension schema change. up() MUST be idempotent and resume safely after
 * implicit-commit DDL; the durable install saga retries it until the migration ledger commits.
 *
 * One implementation is one versioned step an extension ships to build the tables it owns.
 * `ExtensionMigrationRunner` instantiates each class the manifest declares, runs it once per site, and
 * ledgers the run against a digest of the implementation's source file, so a later release cannot reuse
 * an applied ID with different executable bytes. Implementations spell no table name themselves: they
 * ask the supplied `ExtensionTableNames` for one, which is what keeps an extension inside its own
 * namespace and off the core tables. Statements must be portable across MariaDB, MySQL and PostgreSQL.
 *
 * @since  2.0.0
 */
interface ExtensionMigration
{
    /**
     * Return the identifier this migration is ledgered under, unique within the extension.
     *
     * The runner rejects anything that is not a 14-digit timestamp, an underscore, then a lowercase
     * name — `20260804000100_create_announcements` — so the identifier both names the step and orders it.
     * It is fixed once released: changing it makes an installed site apply the migration a second time.
     *
     * @return  string  Timestamped identifier, stable for the life of the migration.
     *
     * @since   2.0.0
     */
    public function id(): string;

    /**
     * Apply the schema change.
     *
     * Must be idempotent and safe to resume: DDL commits implicitly on MySQL and MariaDB, so a run
     * interrupted part way is retried from the beginning rather than rolled back.
     *
     * @param   Connection           $database  Site connection the statements execute on.
     * @param   ExtensionTableNames  $tables    Name compiler scoped to this extension; the only sanctioned
     *          source of physical table names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database, ExtensionTableNames $tables): void;

    /**
     * Undo the schema change.
     *
     * Exists to compensate a failed installation attempt, and is invoked only for migrations that same
     * attempt applied. Uninstall and ordinary upgrades do not call it, so it is not the place to hang
     * destructive cleanup of established site data.
     *
     * @param   Connection           $database  Site connection the statements execute on.
     * @param   ExtensionTableNames  $tables    Name compiler scoped to this extension, resolving the same
     *          physical names `up()` created.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function down(Connection $database, ExtensionTableNames $tables): void;
}
