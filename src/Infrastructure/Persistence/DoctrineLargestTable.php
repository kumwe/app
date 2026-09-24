<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Reports the largest table of this installation, data and indexes together, from the engine's catalogue.
 *
 * Rebuilding a table or one of its indexes online can need up to twice that table's size in temporary
 * space, which is the temporary capacity the storage guardrail keeps free. The figure comes from the
 * catalogue's size statistics for the installation's prefix — `pg_total_relation_size` on PostgreSQL,
 * `data_length + index_length` on MariaDB and MySQL — so it reads no table rows.
 *
 * @since  2.0.0
 */
final readonly class DoctrineLargestTable
{
    /**
     * Bind the probe to the installation.
     *
     * @param  Connection  $database  Installation connection.
     * @param  TableNames  $tables    Installation table names, whose prefix scopes the search.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Size of the installation's largest table.
     *
     * @return  int  Data and index bytes of the largest prefixed table; zero when there is none.
     *
     * @throws  \Doctrine\DBAL\Exception  When the catalogue cannot be read.
     *
     * @since   2.0.0
     */
    public function bytes(): int
    {
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $this->tables->prefix()) . '%';
        $bytes = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? $this->database->fetchOne(
                "SELECT COALESCE(MAX(pg_total_relation_size(c.oid)), 0) FROM pg_class c "
                . "JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ('r', 'p') "
                . 'AND n.nspname = current_schema() AND c.relname LIKE ?',
                [$pattern],
            )
            : $this->database->fetchOne(
                'SELECT COALESCE(MAX(data_length + index_length), 0) FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name LIKE ?',
                [$pattern],
            );

        return is_int($bytes) || (is_string($bytes) && is_numeric($bytes)) ? (int) $bytes : 0;
    }
}
