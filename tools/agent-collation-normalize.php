<?php

/**
 * Normalize every utf8mb4 table and column in the test database to one collation.
 *
 * The immutable parent schema declares its tables with a bare `CHARACTER SET utf8mb4`, so each
 * server resolves their collation from its own charset default: `utf8mb4_general_ci` on MariaDB
 * 10.11 (the Ubuntu package a sandbox installs), a different answer on the `mariadb:lts` image CI
 * runs. Later migrations then deliberately align their columns to `kumwe_sites.identifier`, so
 * whatever the parent schema landed on propagates. On CI the story is internally consistent and
 * every comparison agrees; on a sandbox whose server default differs from the Doctrine-created
 * tables, string comparisons fail with "Illegal mix of collations". What the suite needs is not
 * one specific collation but one *consistent* collation — this tool converges every utf8mb4 table
 * on the database's own default collation, dropping and faithfully re-creating the foreign keys
 * that block a conversion. It is idempotent, converts nothing on a consistent database, and only
 * ever runs against the MariaDB/MySQL drivers.
 *
 * Usage (reads the same DB_* environment the test suite reads):
 *
 *   php tools/agent-collation-normalize.php
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$driver = strtolower((string) getenv('DB_DRIVER') ?: 'mariadb');
if (!in_array($driver, ['mariadb', 'mysql'], true)) {
    echo "Collation normalization only applies to MariaDB/MySQL; nothing to do for {$driver}.\n";
    exit(0);
}

$host = (string) getenv('DB_HOST') ?: '127.0.0.1';
$port = (string) getenv('DB_PORT') ?: '3306';
$name = (string) getenv('DB_NAME') ?: 'kumwe_test';
$user = (string) getenv('DB_USER') ?: 'kumwe';
$password = (string) getenv('DB_PASSWORD') ?: '';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$target = (string) $pdo->query(
    "SELECT default_collation_name FROM information_schema.schemata WHERE schema_name = " . $pdo->quote($name),
)->fetchColumn();
if ($target === '' || !str_starts_with($target, 'utf8mb4')) {
    fwrite(STDERR, "The database default collation ({$target}) is not a utf8mb4 collation; refusing.\n");
    exit(1);
}

$strays = $pdo->query(
    "SELECT DISTINCT c.table_name FROM information_schema.columns c
     JOIN information_schema.tables t
       ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
     WHERE c.table_schema = " . $pdo->quote($name) . "
       AND c.character_set_name = 'utf8mb4' AND c.collation_name <> " . $pdo->quote($target) . "
     UNION
     SELECT t.table_name FROM information_schema.tables t
     WHERE t.table_schema = " . $pdo->quote($name) . " AND t.table_type = 'BASE TABLE'
       AND t.table_collation LIKE 'utf8mb4%' AND t.table_collation <> " . $pdo->quote($target),
)->fetchAll(PDO::FETCH_COLUMN);

if ($strays === []) {
    echo "Every utf8mb4 table already uses {$target}; nothing to convert.\n";
    exit(0);
}

$strayList = implode(', ', array_map($pdo->quote(...), $strays));
$constraints = $pdo->query(
    "SELECT kcu.constraint_name, kcu.table_name, kcu.referenced_table_name,
            rc.delete_rule, rc.update_rule,
            GROUP_CONCAT(kcu.column_name ORDER BY kcu.ordinal_position) AS columns,
            GROUP_CONCAT(kcu.referenced_column_name ORDER BY kcu.ordinal_position) AS referenced_columns
     FROM information_schema.key_column_usage kcu
     JOIN information_schema.referential_constraints rc
       ON rc.constraint_schema = kcu.constraint_schema
      AND rc.constraint_name = kcu.constraint_name
      AND rc.table_name = kcu.table_name
     WHERE kcu.constraint_schema = " . $pdo->quote($name) . "
       AND kcu.referenced_table_name IS NOT NULL
       AND (kcu.table_name IN ({$strayList}) OR kcu.referenced_table_name IN ({$strayList}))
     GROUP BY kcu.constraint_name, kcu.table_name, kcu.referenced_table_name, rc.delete_rule, rc.update_rule",
)->fetchAll(PDO::FETCH_ASSOC);

$quoteId = static fn (string $identifier): string => '`' . str_replace('`', '``', $identifier) . '`';
$quoteIdList = static fn (string $list): string => implode(
    ', ',
    array_map($quoteId, explode(',', $list)),
);

foreach ($constraints as $constraint) {
    $pdo->exec(sprintf(
        'ALTER TABLE %s DROP FOREIGN KEY %s',
        $quoteId($constraint['table_name']),
        $quoteId($constraint['constraint_name']),
    ));
}

foreach ($strays as $table) {
    $pdo->exec(sprintf(
        'ALTER TABLE %s CONVERT TO CHARACTER SET utf8mb4 COLLATE %s',
        $quoteId($table),
        $target,
    ));
    echo "Converted {$table} to {$target}.\n";
}

foreach ($constraints as $constraint) {
    $pdo->exec(sprintf(
        'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
        $quoteId($constraint['table_name']),
        $quoteId($constraint['constraint_name']),
        $quoteIdList($constraint['columns']),
        $quoteId($constraint['referenced_table_name']),
        $quoteIdList($constraint['referenced_columns']),
        $constraint['delete_rule'],
        $constraint['update_rule'],
    ));
}

printf(
    "Normalized %d table(s) to %s and re-created %d foreign key(s).\n",
    count($strays),
    $target,
    count($constraints),
);
