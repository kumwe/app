<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Converges every application table on the database's own default collation after a migration pass.
 *
 * DBAL writes `DEFAULT CHARACTER SET utf8mb4` and no `COLLATE` clause for a table it creates through a
 * schema configuration, so MariaDB and MySQL give that table the *character set's* default collation,
 * while a table created without the clause inherits the *database's* default. On a server where the
 * two agree — the `mariadb:lts` and `mysql:8.4` images with a database the image created — nothing is
 * visible. On a server where they differ, which is what an explicit `COLLATE` in `CREATE DATABASE` or a
 * `collation-server` setting produces, the schema splits into two collations and every join that
 * compares a site identifier or a token subject across the split fails with "Illegal mix of
 * collations". The whole schema needs one collation rather than any particular one, so the migration
 * runner calls this after applying the plan: the application's tables that disagree with the database
 * default are converted to it, with the foreign keys that would block the conversion dropped first and
 * re-created faithfully afterwards. A consistent database is read twice and left untouched; PostgreSQL
 * has one collation per database and is never touched at all.
 *
 * @since  2.0.0
 */
final readonly class SchemaCollationConvergence
{
    /**
     * Bind the convergence to the connection it inspects and the prefix that names the application's tables.
     *
     * @param  Connection  $database  Open connection to the schema a migration pass has just advanced.
     * @param  TableNames  $tables    Prefix every physical application table, generated ones included, begins with.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Return the collation the database itself declares as its default, or null where none applies.
     *
     * Only a `utf8mb4` collation is a target: the connection is opened as `utf8mb4`, so a database whose
     * default is another character set is misconfigured rather than merely inconsistent, and converting
     * the application's tables to it would be the wrong repair.
     *
     * @return  ?string  The `utf8mb4` collation the database defaults to; null on PostgreSQL or when the
     *          database default is not a `utf8mb4` collation.
     *
     * @since   2.0.0
     */
    public function target(): ?string
    {
        if (!$this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return null;
        }
        $collation = $this->database->fetchOne('SELECT @@collation_database');
        if (!is_string($collation) || !str_starts_with($collation, 'utf8mb4')) {
            return null;
        }

        return $collation;
    }

    /**
     * Convert every prefixed table that disagrees with the database default collation, and report which.
     *
     * A table strays when its own collation or any of its `utf8mb4` columns differs from the target.
     * Each foreign key on either side of a straying table is dropped before the conversion and re-created
     * afterwards with the same name, columns, references and rules, because the engine refuses to alter a
     * column's collation while a foreign key still compares it. The conversion keeps the character set,
     * so no column type widens; only the collation moves.
     *
     * @return  list<string>  Physical names of the tables converted, in name order; empty when the
     *          database was already consistent or the platform has no collation to converge on.
     *
     * @throws  \Doctrine\DBAL\Exception  When the catalogue cannot be read or a conversion statement fails.
     *
     * @since   2.0.0
     */
    public function converge(): array
    {
        $target = $this->target();
        if ($target === null) {
            return [];
        }
        $strays = $this->strays($target);
        if ($strays === []) {
            return [];
        }
        $constraints = $this->foreignKeysTouching($strays);

        foreach ($constraints as $constraint) {
            $this->database->executeStatement(sprintf(
                'ALTER TABLE %s DROP FOREIGN KEY %s',
                $this->database->quoteSingleIdentifier($constraint['table']),
                $this->database->quoteSingleIdentifier($constraint['name']),
            ));
        }
        foreach ($strays as $table) {
            $this->database->executeStatement(sprintf(
                'ALTER TABLE %s CONVERT TO CHARACTER SET utf8mb4 COLLATE %s',
                $this->database->quoteSingleIdentifier($table),
                $this->database->quoteSingleIdentifier($target),
            ));
        }
        foreach ($constraints as $constraint) {
            $this->database->executeStatement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
                $this->database->quoteSingleIdentifier($constraint['table']),
                $this->database->quoteSingleIdentifier($constraint['name']),
                $this->quotedList($constraint['columns']),
                $this->database->quoteSingleIdentifier($constraint['referenced_table']),
                $this->quotedList($constraint['referenced_columns']),
                $constraint['delete_rule'],
                $constraint['update_rule'],
            ));
        }

        return $strays;
    }

    /**
     * List the prefixed base tables whose own collation or any `utf8mb4` column collation differs from the target.
     *
     * @param   string  $target  The database default collation every table should carry.
     *
     * @return  list<string>  Physical table names in name order.
     *
     * @since   2.0.0
     */
    private function strays(string $target): array
    {
        $pattern = $this->prefixPattern();
        $rows = $this->database->fetchFirstColumn(
            'SELECT c.TABLE_NAME AS stray FROM information_schema.COLUMNS c'
            . ' INNER JOIN information_schema.TABLES t'
            . ' ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = \'BASE TABLE\''
            . ' WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME LIKE ?'
            . ' AND c.CHARACTER_SET_NAME = \'utf8mb4\' AND c.COLLATION_NAME <> ?'
            . ' UNION'
            . ' SELECT t.TABLE_NAME AS stray FROM information_schema.TABLES t'
            . ' WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME LIKE ? AND t.TABLE_TYPE = \'BASE TABLE\''
            . ' AND t.TABLE_COLLATION LIKE \'utf8mb4%\' AND t.TABLE_COLLATION <> ?',
            [$pattern, $target, $pattern, $target],
        );
        $strays = [];
        foreach ($rows as $row) {
            if (is_string($row) && $row !== '') {
                $strays[$row] = true;
            }
        }
        $names = array_keys($strays);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Describe every foreign key whose referencing or referenced table is among the straying tables.
     *
     * @param   list<string>  $strays  Tables about to be converted.
     *
     * @return  list<array{name: string, table: string, referenced_table: string, columns: list<string>,
     *          referenced_columns: list<string>, delete_rule: string, update_rule: string}>  One entry per
     *          constraint, in constraint-name order.
     *
     * @since   2.0.0
     */
    private function foreignKeysTouching(array $strays): array
    {
        $placeholders = implode(', ', array_fill(0, count($strays), '?'));
        $rows = $this->database->fetchAllAssociative(
            'SELECT kcu.CONSTRAINT_NAME AS name, kcu.TABLE_NAME AS table_name,'
            . ' kcu.REFERENCED_TABLE_NAME AS referenced_table, rc.DELETE_RULE AS delete_rule,'
            . ' rc.UPDATE_RULE AS update_rule,'
            . ' GROUP_CONCAT(kcu.COLUMN_NAME ORDER BY kcu.ORDINAL_POSITION SEPARATOR \',\') AS columns,'
            . ' GROUP_CONCAT(kcu.REFERENCED_COLUMN_NAME ORDER BY kcu.ORDINAL_POSITION SEPARATOR \',\')'
            . ' AS referenced_columns'
            . ' FROM information_schema.KEY_COLUMN_USAGE kcu'
            . ' INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc'
            . ' ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME'
            . ' AND rc.TABLE_NAME = kcu.TABLE_NAME'
            . ' WHERE kcu.CONSTRAINT_SCHEMA = DATABASE() AND kcu.REFERENCED_TABLE_NAME IS NOT NULL'
            . sprintf(
                ' AND (kcu.TABLE_NAME IN (%s) OR kcu.REFERENCED_TABLE_NAME IN (%s))',
                $placeholders,
                $placeholders,
            )
            . ' GROUP BY kcu.CONSTRAINT_NAME, kcu.TABLE_NAME, kcu.REFERENCED_TABLE_NAME, rc.DELETE_RULE, rc.UPDATE_RULE'
            . ' ORDER BY kcu.CONSTRAINT_NAME, kcu.TABLE_NAME',
            [...$strays, ...$strays],
        );
        $constraints = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            $table = $row['table_name'] ?? null;
            $referenced = $row['referenced_table'] ?? null;
            $columns = $row['columns'] ?? null;
            $referencedColumns = $row['referenced_columns'] ?? null;
            $deleteRule = $row['delete_rule'] ?? null;
            $updateRule = $row['update_rule'] ?? null;
            if (
                !is_string($name) || !is_string($table) || !is_string($referenced)
                || !is_string($columns) || !is_string($referencedColumns)
                || !is_string($deleteRule) || !is_string($updateRule)
            ) {
                continue;
            }
            $constraints[] = [
                'name' => $name,
                'table' => $table,
                'referenced_table' => $referenced,
                'columns' => explode(',', $columns),
                'referenced_columns' => explode(',', $referencedColumns),
                'delete_rule' => $this->rule($deleteRule),
                'update_rule' => $this->rule($updateRule),
            ];
        }

        return $constraints;
    }

    /**
     * Admit only the referential actions the engine itself reports, so a rule is never interpolated raw.
     *
     * @param   string  $rule  Action as read from `REFERENTIAL_CONSTRAINTS`.
     *
     * @return  string  The same action, proven to be one of the closed set.
     *
     * @throws  \UnexpectedValueException  When the catalogue reports an action outside the closed set.
     *
     * @since   2.0.0
     */
    private function rule(string $rule): string
    {
        $normalized = strtoupper(trim($rule));
        if (!in_array($normalized, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'], true)) {
            throw new \UnexpectedValueException(sprintf('Unknown referential action "%s".', $rule));
        }

        return $normalized;
    }

    /**
     * Quote a column list for a foreign-key clause.
     *
     * @param   list<string>  $columns  Column names in key order.
     *
     * @return  string  Comma-separated quoted identifiers.
     *
     * @since   2.0.0
     */
    private function quotedList(array $columns): string
    {
        return implode(', ', array_map($this->database->quoteSingleIdentifier(...), $columns));
    }

    /**
     * Build the `LIKE` pattern matching every table that carries the installation's prefix.
     *
     * The prefix's own underscore would otherwise act as a single-character wildcard, so the pattern
     * escapes it; only the trailing wildcard is meant.
     *
     * @return  string  Escaped prefix followed by `%`.
     *
     * @since   2.0.0
     */
    private function prefixPattern(): string
    {
        return addcslashes($this->tables->prefix(), '\\%_') . '%';
    }
}
