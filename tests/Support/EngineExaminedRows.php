<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use RuntimeException;

/**
 * Measures, by the engine, how many rows one captured `SELECT` examined on one table (P5-G).
 *
 * MariaDB and MySQL are measured through the session's storage-engine read counters around a re-run of
 * the statement, which count every row the engine touched, temporary tables included. PostgreSQL is
 * measured through `EXPLAIN (ANALYZE)`: the actual rows, plus those removed by filter or index recheck,
 * of every plan node that reads the table. A plan that scanned or sorted the table shows up as every row.
 *
 * @since  2.0.0
 */
final class EngineExaminedRows
{
    /**
     * Rows the engine examined to answer the statement.
     *
     * @param   Connection                $database  Connection the statement ran on.
     * @param   string                    $sql       Statement, optionally carrying MariaDB's time-bound prefix.
     * @param   array<int|string, mixed>  $params    Its bound values, in placeholder order.
     * @param   string                    $table     Physical table whose rows PostgreSQL counts.
     *
     * @return  int  Examined rows.
     *
     * @throws  RuntimeException  When PostgreSQL returns no decodable plan.
     *
     * @since   2.0.0
     */
    public static function examined(Connection $database, string $sql, array $params, string $table): int
    {
        $sql = self::unbounded($sql);
        if ($database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $plan = $database->fetchOne('EXPLAIN (ANALYZE, FORMAT JSON) ' . self::literal($database, $sql, $params));
            $decoded = json_decode(is_string($plan) ? $plan : '[]', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('PostgreSQL returned no plan.');
            }

            return self::examinedNodes($decoded, $table);
        }
        $before = self::handlerReads($database);
        $database->executeQuery($sql, array_values($params), array_map(
            static fn (mixed $value): ParameterType => is_int($value) ? ParameterType::INTEGER : ParameterType::STRING,
            array_values($params),
        ))->fetchAllAssociative();

        return self::handlerReads($database) - $before;
    }

    /**
     * Strip MariaDB's `SET STATEMENT max_statement_time = … FOR` prefix from a captured statement.
     *
     * @param   string  $sql  Captured statement.
     *
     * @return  string  The bare `SELECT`.
     *
     * @since   2.0.0
     */
    public static function unbounded(string $sql): string
    {
        $sql = ltrim($sql);
        if (preg_match('/^SET STATEMENT max_statement_time = [0-9.]+ FOR (SELECT\b.*)$/Ds', $sql, $bounded) === 1) {
            return $bounded[1];
        }

        return $sql;
    }

    /**
     * Substitute bound values as literals, for statements an engine cannot prepare such as `EXPLAIN`.
     *
     * @param   Connection                $database  Connection whose quoting is used.
     * @param   string                    $sql       Statement with positional placeholders.
     * @param   array<int|string, mixed>  $params    Bound values in order.
     *
     * @return  string  Literal statement.
     *
     * @throws  RuntimeException  When the statement has fewer placeholders than values.
     *
     * @since   2.0.0
     */
    public static function literal(Connection $database, string $sql, array $params): string
    {
        foreach ($params as $value) {
            $replacement = match (true) {
                $value === null => 'NULL',
                $value instanceof DateTimeInterface => $database->quote($value->format('Y-m-d H:i:s.u')),
                is_int($value), is_float($value) => (string) $value,
                is_bool($value) => $value ? 'TRUE' : 'FALSE',
                default => $database->quote(is_scalar($value) ? (string) $value : ''),
            };
            $position = strpos($sql, '?');
            if ($position === false) {
                throw new RuntimeException('The statement has fewer placeholders than bound values.');
            }
            $sql = substr_replace($sql, $replacement, $position, 1);
        }

        return $sql;
    }

    /**
     * Sum actual examined rows over every PostgreSQL plan node reading the table.
     *
     * @param   array<mixed>  $node   Plan node or list.
     * @param   string        $table  Physical table.
     *
     * @return  int  Examined rows.
     *
     * @since   2.0.0
     */
    private static function examinedNodes(array $node, string $table): int
    {
        $examined = 0;
        if (($node['Relation Name'] ?? null) === $table) {
            $loops = is_int($node['Actual Loops'] ?? null) ? $node['Actual Loops'] : 1;
            $rows = 0;
            foreach (['Actual Rows', 'Rows Removed by Filter', 'Rows Removed by Index Recheck'] as $key) {
                $rows += is_int($node[$key] ?? null) ? $node[$key] : 0;
            }
            $examined += $rows * $loops;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $examined += self::examinedNodes($child, $table);
            }
        }

        return $examined;
    }

    /**
     * The session's storage-engine row reads so far.
     *
     * @param   Connection  $database  Connection.
     *
     * @return  int  Sum of the `Handler_read` counters.
     *
     * @since   2.0.0
     */
    private static function handlerReads(Connection $database): int
    {
        $total = 0;
        foreach ($database->fetchAllAssociative("SHOW SESSION STATUS LIKE 'Handler_read%'") as $row) {
            $value = array_values($row)[1] ?? 0;
            $total += is_numeric($value) ? (int) $value : 0;
        }

        return $total;
    }
}
