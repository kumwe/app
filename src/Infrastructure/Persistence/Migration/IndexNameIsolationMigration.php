<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDB1052Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Index;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Gives every installed non-primary index a name no second installation in the same schema can collide with.
 *
 * On PostgreSQL an index name is **schema-global**: an index is a relation, and two relations in one
 * schema may not share a name, however different the tables they belong to are. The shipped migrations
 * spell one hundred and ten index names literally — `uniq_org_site_identifier`, `idx_job_claim` — which
 * is unique enough inside a single installation and fatal beside a second one: installing a second
 * prefixed Kumwe into a schema that already holds one failed at `CREATE UNIQUE INDEX` on the first
 * shared literal. The MySQL family scopes an index name to its table and never had the collision, but
 * it is renamed there too so that one naming shape describes every supported engine, exactly as
 * `ConstraintNameIsolationMigration` already does for foreign keys.
 *
 * The repair has to be a rename rather than an edit to the migrations that created the names, because
 * `CoreSchemaMigration` and its successors publish their own file digests as an immutability contract:
 * changing their bytes breaks every installed site's upgrade path. So they keep creating the literal
 * names, and this runs afterwards — last in the plan — and renames what they created, which is also
 * precisely what **frees** each literal name for the next installation to create in its turn. A second
 * installation therefore becomes possible only once the first has migrated past this point.
 *
 * A name is left alone only when it already ends in a digest suffix of sixteen or more hexadecimal
 * characters, matched case-insensitively: that shape covers this migration's own targets, the
 * `<stem>_<16 hex>` names four shipped migrations always derived, the 20-hex business-schema compiler
 * names, the 24-hex translation-group name, and the crc-derived implicit foreign-key indexes DBAL
 * spells in uppercase on the MySQL family. A prefix match is deliberately **not** proof of isolation:
 * `idx_` and `uniq_` are themselves valid table prefixes, and trusting them would skip the entire
 * repair for an installation configured that way — the same lesson
 * `ConstraintNameIsolationPortabilityMigration` records for `fk_`. Everything else is renamed to
 * `<stem>_<digest>`, where the digest covers the physical table name and the original stem, so the
 * result differs between installations and the truncation the longest stem needs cannot make two
 * names on one table equal. The whole body is guarded and re-entrant, which is what lets it declare
 * `RepeatableMigration` and what makes it idempotent against a partially renamed schema.
 *
 * @since  2.0.0
 */
final readonly class IndexNameIsolationMigration implements RepeatableMigration
{
    /**
     * Stable ordered migration identity, appended after the number-sequence identity migration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260823010000_schema_global_index_names';

    /**
     * Longest identifier the rename may produce, being the smallest limit across the supported engines.
     *
     * PostgreSQL truncates an identifier at 63 bytes and MySQL refuses an index name beyond 64, so 63
     * is the portable ceiling and the one the stem is trimmed against.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_IDENTIFIER_BYTES = ConstraintNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES;

    /**
     * Fewest hexadecimal characters a trailing digest must have for a name to count as already unique.
     *
     * Sixteen is what this migration appends; the recognisers accept longer runs because the shipped
     * derivations it must leave alone end in sixteen, twenty and twenty-four hexadecimal characters.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int DIGEST_LENGTH = 16;

    /**
     * Bind the rename to the installation's physical table names and its configured prefix.
     *
     * @param  TableNames  $tables  Prefix-aware table-name compiler, also used to decide which tables of
     *         a shared schema belong to this installation.
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
     * Bind migration compatibility to the exact rename and delegated target-name implementation.
     *
     * The target derivation is delegated to the published constraint isolation, so both source files
     * are inputs: editing either produces a new checksum instead of silently changing what an
     * installed database is asked to accept.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When this source file or the delegated target-name source cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $ownDigest = hash_file('sha256', __FILE__);
        if (!is_string($ownDigest)) {
            throw new RuntimeException('The index name isolation checksum could not be calculated.');
        }
        $digests = [$ownDigest];
        foreach ([__DIR__ . '/ConstraintNameIsolationMigration.php'] as $source) {
            $digest = hash_file('sha256', $source);
            if (!is_string($digest)) {
                throw new RuntimeException('The index name isolation checksum could not be calculated.');
            }
            $digests[] = $digest;
        }

        return hash('sha256', self::ID . ':' . implode(':', $digests));
    }

    /**
     * Compile the installation-unique name an index on one table is renamed to.
     *
     * The derivation is the published constraint one, unchanged: the digest covers the physical table
     * name, which is what makes the result differ between two installations of one schema, and the
     * original stem, which is what keeps two indexes on one table apart after a long stem has been
     * trimmed to fit the portable identifier limit.
     *
     * @param   string  $tableName  Prefixed physical name of the table carrying the index.
     * @param   string  $stem       Index name as the migration that created it spelled it.
     *
     * @return  non-empty-string  Name of at most 63 bytes, unique to this installation and this table.
     *
     * @throws  RuntimeException  When the stem is empty, leaving nothing to name the index after.
     *
     * @since   2.0.0
     */
    public static function isolatedName(string $tableName, string $stem): string
    {
        return ConstraintNameIsolationMigration::isolatedName($tableName, $stem);
    }

    /**
     * Report whether an index name still has to be made unique to this installation.
     *
     * Only the derived-suffix shape is already safe: an underscore followed by sixteen or more
     * hexadecimal characters ending the name, matched case-insensitively because DBAL spells its
     * crc-derived implicit index names in uppercase on the MySQL family and PostgreSQL folds the same
     * names to lowercase. Prefix matching is deliberately not considered: `idx_` and `uniq_` are valid
     * table prefixes and also begin every shipped literal, so treating a prefix as proof would skip
     * the entire repair for that installation.
     *
     * @param   string  $name  Index name as the database reports it.
     *
     * @return  bool  True when the name is still a literal shared with every other installation.
     *
     * @since   2.0.0
     */
    public static function needsIsolation(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        return preg_match(sprintf('/_[0-9a-f]{%d,}$/Di', self::DIGEST_LENGTH), $name) !== 1;
    }

    /**
     * Rename every collision-prone non-primary index of this installation, table by table.
     *
     * Tables carrying a longer neighbouring installation prefix are excluded even though they also begin
     * with this installation's prefix. The neighbouring prefixes come from their migration-ledger table,
     * the durable marker every initialized Kumwe installation owns. Primary keys are skipped: their
     * backing index is named after the physical table on every supported engine and cannot collide. A
     * table whose indexes are already unique executes no statement, which is what makes an interrupted
     * attempt replayable in full.
     *
     * The statements are composed from the platform rather than from a schema comparison, because a
     * comparator that finds two indexes structurally identical reports no difference between them and
     * a pure rename is exactly that case: `Comparator::compareTables()` would silently produce an
     * empty diff and this migration would record itself as applied having changed nothing.
     *
     * @param   Connection  $database  Installation database whose index names are isolated.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an index carries no readable name, an interrupted replay's
     *          target has a different shape, or the rename leaves a name still schema-global.
     * @throws  \Doctrine\DBAL\Exception  When the driver refuses one of the generated statements.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $prefix = $this->tables->prefix();
        $tableNames = [];
        foreach ($manager->introspectTableNames() as $name) {
            $tableNames[] = $name->getUnqualifiedName()->getValue();
        }
        $installationPrefixes = $this->installationPrefixes($tableNames);
        foreach ($tableNames as $tableName) {
            if (!$this->belongsToInstallation($tableName, $prefix, $installationPrefixes)) {
                continue;
            }

            $indexes = $manager->introspectTableByUnquotedName($tableName)->getIndexes();
            $present = [];
            foreach ($indexes as $index) {
                $present[$this->indexName($index, $tableName)] = $index;
            }

            $statements = [];
            foreach ($indexes as $index) {
                if ($this->backsPrimaryKey($index)) {
                    continue;
                }
                $current = $this->indexName($index, $tableName);
                if (!self::needsIsolation($current)) {
                    continue;
                }
                $target = self::isolatedName($tableName, $current);
                $created = $present[$target] ?? null;
                if ($created !== null && !$this->sameShape($index, $created)) {
                    throw new RuntimeException(sprintf(
                        'Index "%s" on table "%s" cannot resume because target "%s" has a different shape.',
                        $current,
                        $tableName,
                        $target,
                    ));
                }
                $statements = [...$statements, ...$this->renameStatements(
                    $database,
                    $tableName,
                    $current,
                    $target,
                    $index,
                    $created !== null,
                )];
                $present[$target] = $created ?? $this->renamed($index, $target);
            }
            if ($statements === []) {
                continue;
            }

            foreach ($statements as $statement) {
                $database->executeStatement($statement);
            }
            $this->assertIsolated($database, $tableName);
        }
    }

    /**
     * Derive initialized Kumwe installation prefixes from their migration-ledger table names.
     *
     * @param   list<string>  $tableNames  Unqualified table names visible in the current schema.
     *
     * @return  list<string>  Prefixes in schema order, including this installation when its ledger exists.
     *
     * @since   2.0.0
     */
    private function installationPrefixes(array $tableNames): array
    {
        $ledger = 'schema_migrations';
        $prefixes = [];
        foreach ($tableNames as $tableName) {
            if (!str_ends_with($tableName, $ledger)) {
                continue;
            }
            $prefix = substr($tableName, 0, -strlen($ledger));
            if ($prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * Decide whether one prefixed table belongs to this installation rather than a longer-prefix neighbour.
     *
     * @param   string        $tableName             Unqualified physical table name to classify.
     * @param   string        $prefix                Prefix of the installation being migrated.
     * @param   list<string>  $installationPrefixes  Prefixes derived from migration ledgers in this schema.
     *
     * @return  bool  True when the table starts with this prefix and no longer known prefix owns it.
     *
     * @since   2.0.0
     */
    private function belongsToInstallation(string $tableName, string $prefix, array $installationPrefixes): bool
    {
        if (!str_starts_with($tableName, $prefix)) {
            return false;
        }
        foreach ($installationPrefixes as $candidate) {
            if (
                $candidate !== $prefix
                && str_starts_with($candidate, $prefix)
                && str_starts_with($tableName, $candidate)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compose the statements that move one index from its shared name to its isolated one.
     *
     * PostgreSQL and the current MySQL family both rename the index in the catalogue, which costs
     * nothing and keeps the built structure: `ALTER INDEX … RENAME TO` on PostgreSQL, and
     * `ALTER TABLE … RENAME INDEX` on MySQL 8 and MariaDB 10.5.2 or later. A MySQL-family platform
     * older than that has no rename for an index, so there the index is created under its new name and
     * the old one is then dropped — **create first, then drop**, because where DDL commits implicitly
     * an attempt interrupted between the two statements has already committed the first, and dropping
     * first would leave the table without the index for a replay to find. The transient twin enforces
     * the same rule twice and loses nothing; the replay sees the target present, verifies its shape,
     * skips the create and drops the old name as it was going to.
     *
     * @param   Connection        $database   Connection whose platform composes the statements.
     * @param   non-empty-string  $tableName  Prefixed physical name of the table carrying the index.
     * @param   non-empty-string  $current    Name the index carries now.
     * @param   non-empty-string  $target     Name it is to carry afterwards.
     * @param   Index             $index      Index as introspected, supplying its shape for a rebuild.
     * @param   bool              $created    Whether the target name is already on the table, which is
     *          how an attempt interrupted between two fallback statements resumes without failing.
     *
     * @return  list<string>  One or two statements, in the order they must run.
     *
     * @since   2.0.0
     */
    private function renameStatements(
        Connection $database,
        string $tableName,
        string $current,
        string $target,
        Index $index,
        bool $created,
    ): array {
        $platform = $database->getDatabasePlatform();
        $table = $database->quoteSingleIdentifier($tableName);
        $drop = $platform->getDropIndexSQL($database->quoteSingleIdentifier($current), $table);
        if ($created) {
            return [$drop];
        }
        if ($platform instanceof PostgreSQLPlatform) {
            return [sprintf(
                'ALTER INDEX %s RENAME TO %s',
                $database->quoteSingleIdentifier($current),
                $database->quoteSingleIdentifier($target),
            )];
        }
        if ($platform instanceof MySQLPlatform || $platform instanceof MariaDB1052Platform) {
            return [sprintf(
                'ALTER TABLE %s RENAME INDEX %s TO %s',
                $table,
                $database->quoteSingleIdentifier($current),
                $database->quoteSingleIdentifier($target),
            )];
        }

        return [$platform->getCreateIndexSQL($this->renamed($index, $target), $table), $drop];
    }

    /**
     * Rebuild one index under a new name, carrying every structural property across unchanged.
     *
     * `Index::edit()` copies the type, the ordered columns with their lengths, the clustered flag and
     * the predicate, so the fallback recreation installs exactly the structure introspection reported
     * rather than a default one.
     *
     * @param   Index             $index  Index as introspected from the live schema.
     * @param   non-empty-string  $name   Name the rebuilt index is to carry.
     *
     * @return  Index  Same structure, under the new name.
     *
     * @since   2.0.0
     */
    private function renamed(Index $index, string $name): Index
    {
        return $index->edit()->setUnquotedName($name)->create();
    }

    /**
     * Prove an existing replay target is the same index as the source still waiting to be dropped.
     *
     * @param   Index  $source  Shared-name index the migration is resuming.
     * @param   Index  $target  Pre-existing isolated-name index found beside it.
     *
     * @return  bool  True only when type, ordered columns, lengths, predicate and clustering all match.
     *
     * @since   2.0.0
     */
    private function sameShape(Index $source, Index $target): bool
    {
        return $source->getType() === $target->getType()
            && $this->columns($source) === $this->columns($target)
            && $source->getPredicate() === $target->getPredicate()
            && $source->isClustered() === $target->isClustered();
    }

    /**
     * Normalize one index's ordered columns and prefix lengths for structural comparison.
     *
     * @param   Index  $index  Index as introspected from the live schema.
     *
     * @return  list<string>  Column names in index order, each with its prefix length where one is set.
     *
     * @since   2.0.0
     */
    private function columns(Index $index): array
    {
        $columns = [];
        foreach ($index->getIndexedColumns() as $column) {
            $columns[] = $column->getColumnName()->getIdentifier()->getValue() . ':' . ($column->getLength() ?? 0);
        }

        return $columns;
    }

    /**
     * Report whether one introspected index is the primary key's backing index.
     *
     * DBAL's schema managers report the primary key's backing index under the portable name
     * `primary` on every supported engine, substituting it for the physical `PRIMARY` or
     * `<table>_pkey` spelling. That backing index is skipped: its physical name derives from the
     * prefixed table on every supported engine, so it cannot collide, and renaming it would detach
     * the name from the constraint that owns it.
     *
     * @param   Index  $index  Index as introspected from the live schema.
     *
     * @return  bool  True when the index is the primary key's backing index.
     *
     * @since   2.0.0
     */
    private function backsPrimaryKey(Index $index): bool
    {
        return strtolower($index->getObjectName()->getIdentifier()->getValue()) === 'primary';
    }

    /**
     * Read the name an index carries, refusing one the schema reports without a usable name.
     *
     * @param   Index   $index      Index as introspected from the live schema.
     * @param   string  $tableName  Table the index sits on, named in the failure.
     *
     * @return  non-empty-string  Index name exactly as the database spells it.
     *
     * @throws  RuntimeException  When the index carries no name to rename it from.
     *
     * @since   2.0.0
     */
    private function indexName(Index $index, string $tableName): string
    {
        $name = $index->getObjectName()->getIdentifier()->getValue();
        if ($name === '') {
            throw new RuntimeException(sprintf(
                'An index on table "%s" carries no name and cannot be made installation-unique.',
                $tableName,
            ));
        }

        return $name;
    }

    /**
     * Prove one table's non-primary indexes are all unique to this installation after the rename ran.
     *
     * Asserted rather than assumed because the whole value of the migration is the property it claims,
     * and a driver that quietly ignored a rename would otherwise leave a second installation to
     * discover it at `CREATE INDEX`.
     *
     * @param   Connection        $database   Database the repaired table is read back from.
     * @param   non-empty-string  $tableName  Prefixed physical name of the table just repaired.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a non-primary index on the table is still named collision-prone.
     *
     * @since   2.0.0
     */
    private function assertIsolated(Connection $database, string $tableName): void
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName($tableName);
        foreach ($table->getIndexes() as $index) {
            if ($this->backsPrimaryKey($index)) {
                continue;
            }
            $name = $index->getObjectName()->getIdentifier()->getValue();
            if (self::needsIsolation($name)) {
                throw new RuntimeException(sprintf(
                    'Index "%s" on table "%s" is still named schema-globally after the rename.',
                    $name,
                    $tableName,
                ));
            }
        }
    }
}
