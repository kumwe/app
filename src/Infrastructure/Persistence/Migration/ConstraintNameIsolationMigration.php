<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Gives every installed foreign key a name no second installation in the same schema can collide with.
 *
 * On MySQL and MariaDB a foreign-key constraint name is **schema-global**, not table-local: two tables
 * in one schema may not carry constraints of the same name. The shipped migrations name fifty-four
 * constraints literally — `fk_org_site`, `fk_password_user` — which is unique enough inside a single
 * installation and fatal beside a second one. Installing a second prefixed Kumwe into a schema that
 * already holds one therefore failed at `CREATE TABLE` with `Duplicate foreign key constraint name`,
 * or on MariaDB with the errno 121 that
 * `MigrationIntegrationTest::testBusinessSecuritySiteForeignKeyUsesTheExistingMariaDbCollation`
 * reproduces. PostgreSQL scopes the name to the table and never had the collision, but it is renamed
 * there too so that one schema shape describes every supported engine.
 *
 * The repair has to be a rename rather than an edit to the migrations that created the names, because
 * `CoreSchemaMigration` and its successors publish their own file digests as an immutability contract:
 * changing their bytes breaks every installed site's upgrade path. So they keep creating the literal
 * names, and this runs afterwards and renames what they created — which is also precisely what **frees**
 * each literal name for the next installation to create in its turn. The consequence is worth stating
 * plainly: a second installation becomes possible only once the first has migrated past this point.
 *
 * A name is left alone when it is already unique to this installation, either because it carries the
 * installation's table prefix or because it already ends in the derived suffix. Everything else is
 * dropped and recreated under `<stem>_<digest>`, where the digest covers both the physical table name
 * and the original stem, so the truncation the longest stem needs cannot make two names equal. The
 * whole body is guarded and re-entrant, which is what lets it declare `RepeatableMigration` and what
 * makes it idempotent against a partially renamed schema.
 *
 * @since  2.0.0
 */
final readonly class ConstraintNameIsolationMigration implements RepeatableMigration
{
    /**
     * Stable ordered migration identity, appended after the resource-ownership scope migration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260818010000_schema_global_constraint_names';

    /**
     * Longest identifier the rename may produce, being the smallest limit across the supported engines.
     *
     * PostgreSQL truncates an identifier at 63 bytes and MySQL refuses a constraint name beyond 64, so
     * 63 is the portable ceiling and the one the stem is trimmed against.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_IDENTIFIER_BYTES = 63;

    /**
     * Hexadecimal characters of the per-installation digest appended to every renamed constraint.
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
     * Bind migration compatibility to the exact rename implementation.
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
            throw new RuntimeException('The constraint name isolation checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Compile the installation-unique name a constraint on one table is renamed to.
     *
     * The digest covers the physical table name, which is what makes the result differ between two
     * installations of one schema, and the original stem, which is what keeps two constraints on one
     * table apart after a long stem has been trimmed to fit the portable identifier limit.
     *
     * @param   string  $tableName  Prefixed physical name of the table carrying the constraint.
     * @param   string  $stem       Constraint name as the migration that created it spelled it.
     *
     * @return  non-empty-string  Name of at most 63 bytes, unique to this installation and this table.
     *
     * @throws  RuntimeException  When the stem is empty, leaving nothing to name the constraint after.
     *
     * @since   2.0.0
     */
    public static function isolatedName(string $tableName, string $stem): string
    {
        if ($stem === '') {
            throw new RuntimeException('A foreign key constraint cannot be renamed from an empty name.');
        }

        $digest = substr(hash('sha256', $tableName . ':' . $stem), 0, self::DIGEST_LENGTH);
        $budget = self::MAXIMUM_IDENTIFIER_BYTES - self::DIGEST_LENGTH - 1;

        return substr($stem, 0, $budget) . '_' . $digest;
    }

    /**
     * Report whether a constraint name still has to be made unique to this installation.
     *
     * Two shapes are already safe and are left exactly as they are. A name carrying the installation's
     * table prefix cannot collide, because two installations sharing one schema must differ by prefix
     * or their tables would already collide. A name ending in the derived suffix has been through this
     * rename, or was derived at creation by one of the migrations that always did so.
     *
     * @param   string  $name    Constraint name as the database reports it.
     * @param   string  $prefix  Table prefix of the installation being migrated.
     *
     * @return  bool  True when the name is still a literal shared with every other installation.
     *
     * @since   2.0.0
     */
    public static function needsIsolation(string $name, string $prefix): bool
    {
        if ($name === '' || str_starts_with($name, $prefix)) {
            return false;
        }

        return preg_match(sprintf('/_[0-9a-f]{%d}$/D', self::DIGEST_LENGTH), $name) !== 1;
    }

    /**
     * Rename every collision-prone foreign key of this installation, table by table.
     *
     * Only tables carrying this installation's prefix are touched, so an installation living beneath a
     * parent schema never renames a neighbour's constraints. A table whose constraints are already
     * unique executes no statement, which is what makes an interrupted attempt replayable in full.
     *
     * The statements are composed from the platform rather than from a schema comparison, because a
     * comparator that finds two constraints structurally identical reports no difference between them
     * and a pure rename is exactly that case: `Comparator::compareTables()` would silently produce an
     * empty diff and this migration would record itself as applied having changed nothing.
     *
     * @param   Connection  $database  Installation database whose constraint names are isolated.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a constraint the schema reports carries no readable name, or the
     *          rename leaves a name still shared with every other installation.
     * @throws  \Doctrine\DBAL\Exception  When the driver refuses one of the generated statements.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $prefix = $this->tables->prefix();
        foreach ($manager->introspectTableNames() as $name) {
            $tableName = $name->getUnqualifiedName()->getValue();
            if (!str_starts_with($tableName, $prefix)) {
                continue;
            }

            $constraints = $manager->introspectTableByUnquotedName($tableName)->getForeignKeys();
            $present = [];
            foreach ($constraints as $constraint) {
                $present[$this->constraintName($constraint, $tableName)] = true;
            }

            $statements = [];
            foreach ($constraints as $constraint) {
                $current = $this->constraintName($constraint, $tableName);
                if (!self::needsIsolation($current, $prefix)) {
                    continue;
                }
                $target = self::isolatedName($tableName, $current);
                $statements = [...$statements, ...$this->renameStatements(
                    $database,
                    $tableName,
                    $current,
                    $target,
                    $constraint,
                    isset($present[$target]),
                )];
                $present[$target] = true;
            }
            if ($statements === []) {
                continue;
            }

            foreach ($statements as $statement) {
                $database->executeStatement($statement);
            }
            $this->assertIsolated($database, $tableName, $prefix);
        }
    }

    /**
     * Compose the statements that move one constraint from its shared name to its isolated one.
     *
     * PostgreSQL renames the constraint in its catalogue, which costs nothing and keeps the existing
     * validation. The MySQL family has no rename for a foreign key, so the constraint is created under its
     * new name and the old one is then dropped — which is also the operation that frees the shared name for
     * the next installation to take. The recreation carries the referential properties across explicitly
     * rather than relying on defaults, so an `ON DELETE CASCADE` is still an `ON DELETE CASCADE` afterwards.
     *
     * **The order is create-then-drop, and that is the whole point of it.** Where DDL commits implicitly,
     * an attempt interrupted between the two statements has already committed the first. Dropping first
     * would leave the table with no such foreign key at all, and a replay would find nothing to rename and
     * silently declare the migration done having lost a constraint. Creating first leaves the table
     * transiently holding both names, which enforces the same rule twice and loses nothing; the replay then
     * sees the target already present, skips the create, and drops the old name as it was going to.
     *
     * @param   Connection            $database    Connection whose platform composes the statements.
     * @param   non-empty-string      $tableName   Prefixed physical name of the table being altered.
     * @param   non-empty-string      $current     Name the constraint carries now.
     * @param   non-empty-string      $target      Name it is to carry afterwards.
     * @param   ForeignKeyConstraint  $constraint  Constraint as introspected, supplying its shape.
     * @param   bool                  $created     Whether the target name is already on the table, which is
     *          how an attempt interrupted between the two statements resumes without failing on a duplicate.
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
        ForeignKeyConstraint $constraint,
        bool $created,
    ): array {
        $platform = $database->getDatabasePlatform();
        $table = $database->quoteSingleIdentifier($tableName);
        if ($platform instanceof PostgreSQLPlatform) {
            return [sprintf(
                'ALTER TABLE %s RENAME CONSTRAINT %s TO %s',
                $table,
                $database->quoteSingleIdentifier($current),
                $database->quoteSingleIdentifier($target),
            )];
        }

        $drop = $platform->getDropForeignKeySQL($database->quoteSingleIdentifier($current), $table);
        if ($created) {
            return [$drop];
        }

        return [$platform->getCreateForeignKeySQL($this->renamed($constraint, $target), $table), $drop];
    }

    /**
     * Rebuild one constraint under a new name, carrying every referential property across unchanged.
     *
     * The properties are read through the typed accessors rather than the option bag, so a referential
     * action, match type or deferrability the schema actually carries survives the rename instead of
     * being silently replaced by a default.
     *
     * @param   ForeignKeyConstraint  $constraint  Constraint as introspected from the live schema.
     * @param   non-empty-string      $name        Name the rebuilt constraint is to carry.
     *
     * @return  ForeignKeyConstraint  Same referencing and referenced shape, under the new name.
     *
     * @since   2.0.0
     */
    private function renamed(ForeignKeyConstraint $constraint, string $name): ForeignKeyConstraint
    {
        $referencing = $constraint->getReferencingColumnNames();
        $referenced = $constraint->getReferencedColumnNames();
        $editor = ForeignKeyConstraint::editor()
            ->setUnquotedName($name)
            ->setReferencingColumnNames(...$referencing)
            ->setReferencedTableName($constraint->getReferencedTableName())
            ->setReferencedColumnNames(...$referenced)
            ->setMatchType($constraint->getMatchType())
            ->setOnUpdateAction($constraint->getOnUpdateAction())
            ->setOnDeleteAction($constraint->getOnDeleteAction());

        return $editor->setDeferrability($constraint->getDeferrability())->create();
    }

    /**
     * Read the name a constraint carries, refusing one the schema reports without a usable name.
     *
     * @param   ForeignKeyConstraint  $constraint  Constraint as introspected from the live schema.
     * @param   string                $tableName   Table the constraint sits on, named in the failure.
     *
     * @return  non-empty-string  Constraint name exactly as the database spells it.
     *
     * @throws  RuntimeException  When the constraint carries no name to rename it from.
     *
     * @since   2.0.0
     */
    private function constraintName(ForeignKeyConstraint $constraint, string $tableName): string
    {
        $name = $constraint->getObjectName()?->getIdentifier()->getValue();
        if ($name === null || $name === '') {
            throw new RuntimeException(sprintf(
                'A foreign key on table "%s" carries no name and cannot be made installation-unique.',
                $tableName,
            ));
        }

        return $name;
    }

    /**
     * Prove one table's constraints are all unique to this installation after the rename ran.
     *
     * Asserted rather than assumed because the whole value of the migration is the property it claims,
     * and a driver that quietly ignored a rename would otherwise leave a second installation to
     * discover it at `CREATE TABLE`.
     *
     * @param   Connection        $database   Database the repaired table is read back from.
     * @param   non-empty-string  $tableName  Prefixed physical name of the table just repaired.
     * @param   string            $prefix     Table prefix of the installation being migrated.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a constraint on the table is still named collision-prone.
     *
     * @since   2.0.0
     */
    private function assertIsolated(Connection $database, string $tableName, string $prefix): void
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName($tableName);
        foreach ($table->getForeignKeys() as $constraint) {
            $name = $constraint->getObjectName()?->getIdentifier()->getValue() ?? '';
            if (self::needsIsolation($name, $prefix)) {
                throw new RuntimeException(sprintf(
                    'Foreign key "%s" on table "%s" is still named schema-globally after the rename.',
                    $name,
                    $tableName,
                ));
            }
        }
    }
}
