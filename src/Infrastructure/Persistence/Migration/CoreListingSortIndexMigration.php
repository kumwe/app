<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Indexes the two core listings whose ordering no installed index delivered (P5-G).
 *
 * The content browser's title orderings (`title_asc`, `title_desc`) read one site's entries ordered by
 * title and identifier, and the operator's recent-process listing reads every process instance ordered by
 * last update and identifier. Neither had an index leading with those columns, so both sorted every row
 * of an aged table before returning the first batch. `(site_identifier, title, id)` and
 * `(updated_at, process_id)` let MariaDB, MySQL and PostgreSQL read those pages in index order, forwards
 * or backwards. The content browser's default and oldest-first orderings are already served by
 * `(site_identifier, updated_at, id)`.
 *
 * Index names are derived from the prefixed table names, as the other index migrations do, so two
 * installations sharing one schema do not collide on them.
 *
 * @since  2.0.0
 */
final readonly class CoreListingSortIndexMigration implements RepeatableMigration
{
    /**
     * Stable ordered migration identity, appended after the audit retention authority.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260930120000_core_listing_sort_indexes';

    /**
     * Bind the indexes to the installation's physical table names.
     *
     * @param  TableNames  $tables  Prefix-aware table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the append-only migration identity stored in the schema ledger.
     *
     * @return  string  Stable ordered migration identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind migration compatibility to the exact indexes this build declares.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When this source file cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The core listing sort index checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Add each listing index unless its table already carries it.
     *
     * @param   Connection  $database  Installation database whose core tables are indexed.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When a table cannot be introspected or altered.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        foreach (self::indexes($this->tables) as $table => [$name, $columns]) {
            $manager = $database->createSchemaManager();
            $before = $manager->introspectTableByUnquotedName($table);
            if ($before->hasIndex($name)) {
                continue;
            }
            $after = clone $before;
            $after->addIndex($columns, $name);
            $difference = $manager->createComparator()->compareTables($before, $after);
            foreach ($database->getDatabasePlatform()->getAlterTableSQL($difference) as $statement) {
                $database->executeStatement($statement);
            }
        }
    }

    /**
     * Name and columns of each listing index, keyed by the prefixed table it belongs to.
     *
     * @param   TableNames  $tables  Prefix-aware table-name compiler.
     *
     * @return  array<non-empty-string, array{non-empty-string, non-empty-list<non-empty-string>}>  Index name and
     *          ordered columns per table.
     *
     * @since   2.0.0
     */
    public static function indexes(TableNames $tables): array
    {
        $content = $tables->raw('content_entries');
        $processes = $tables->raw('business_process_instances');

        return [
            $content => [
                'idx_content_site_title_' . substr(hash('sha256', $content), 0, 16),
                ['site_identifier', 'title', 'id'],
            ],
            $processes => [
                'idx_business_process_recent_' . substr(hash('sha256', $processes), 0, 16),
                ['updated_at', 'process_id'],
            ],
        ];
    }
}
