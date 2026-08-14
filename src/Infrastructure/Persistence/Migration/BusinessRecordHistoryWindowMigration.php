<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Indexes the revision log for the total ordering a history page is now read in.
 *
 * A history window found by identity digest is ordered by record version, then revision number, then the
 * internal record key, because the digest alone can cover more than one generation of the same public
 * identity and the first two components can therefore repeat. The existing identity index stops at the
 * digest, so the engine had to sort every matching row to answer the window. This index carries the three
 * ordering columns behind the same equality prefix, which lets both supported engines read the page
 * straight out of the index in the order the caller asked for, and lets the generation probe that runs
 * before it resolve its distinct record keys without touching the table at all.
 *
 * The index name is derived from the prefixed table name rather than written literally, matching the
 * convention the theme-surface and authorization-recovery migrations already use, so two installations
 * sharing one schema do not collide on it.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordHistoryWindowMigration implements RepeatableMigration
{
    /**
     * Stable ordered migration identity, appended after the ownership portability repair.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260815020000_business_record_history_window';

    /**
     * Ordering columns appended to the identity equality prefix, newest-first when read backwards.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array WINDOW_COLUMNS = [
        'definition_id',
        'site_identifier',
        'record_identity_digest',
        'record_version',
        'revision_number',
        'record_id',
    ];

    /**
     * Bind the index to the installation's physical table names.
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
     * Bind migration compatibility to the exact index this build declares.
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
            throw new RuntimeException('The business record history window checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Add the ordering index unless the revision log already carries it.
     *
     * @param   Connection  $database  Installation database whose revision log is indexed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $revisions = $this->tables->raw('business_record_revisions');
        $before = $manager->introspectTableByUnquotedName($revisions);
        $name = self::indexName($revisions);
        if ($before->hasIndex($name)) {
            return;
        }

        $after = clone $before;
        $after->addIndex(self::WINDOW_COLUMNS, $name);
        $difference = $manager->createComparator()->compareTables($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterTableSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Compile the per-installation name this index is created under.
     *
     * @param   string  $revisionsTable  Prefixed physical name of the revision log.
     *
     * @return  string  Index name unique to this installation's revision log.
     *
     * @since   2.0.0
     */
    public static function indexName(string $revisionsTable): string
    {
        return 'idx_brecord_revision_window_' . substr(hash('sha256', $revisionsTable), 0, 16);
    }
}
