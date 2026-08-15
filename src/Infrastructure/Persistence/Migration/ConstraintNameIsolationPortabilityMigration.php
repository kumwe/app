<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Name;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Completes the published constraint-name isolation without changing its checksum-bound source.
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
 * `ConstraintNameIsolationMigration` is already published and checksum-bound, so correcting it in place
 * would reject every database that applied the original bytes. This append-only follow-up keeps the same
 * target-name derivation and completes three postconditions the original could miss: `fk_` is a valid
 * installation prefix rather than proof a literal is already isolated; a longer initialized prefix owns
 * its own tables; and a replay target is trusted only when it has the source constraint's exact shape.
 *
 * A name is left alone only when it already ends in the derived suffix. Everything else is dropped and
 * recreated under `<stem>_<digest>`, where the digest covers both the physical table name
 * and the original stem, so the truncation the longest stem needs cannot make two names equal. The
 * whole body is guarded and re-entrant, which is what lets it declare `RepeatableMigration` and what
 * makes it idempotent against a partially renamed schema.
 *
 * @since  2.0.0
 */
final readonly class ConstraintNameIsolationPortabilityMigration implements RepeatableMigration
{
    /**
     * Stable ordered migration identity, appended after the translation-group ownership repair.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260820010000_constraint_name_isolation_portability';

    /**
     * Longest identifier the rename may produce, being the smallest limit across the supported engines.
     *
     * PostgreSQL truncates an identifier at 63 bytes and MySQL refuses a constraint name beyond 64, so
     * 63 is the portable ceiling and the one the stem is trimmed against.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_IDENTIFIER_BYTES = ConstraintNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES;

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
     * Bind migration compatibility to the exact rename and delegated target-name implementation.
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
            throw new RuntimeException('The constraint name isolation checksum could not be calculated.');
        }
        $digests = [$ownDigest];
        foreach ([__DIR__ . '/ConstraintNameIsolationMigration.php'] as $source) {
            $digest = hash_file('sha256', $source);
            if (!is_string($digest)) {
                throw new RuntimeException('The constraint name isolation checksum could not be calculated.');
            }
            $digests[] = $digest;
        }

        return hash('sha256', self::ID . ':' . implode(':', $digests));
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
        return ConstraintNameIsolationMigration::isolatedName($tableName, $stem);
    }

    /**
     * Report whether a constraint name still has to be made unique to this installation.
     *
     * Only the derived-suffix shape is already safe. Prefix matching is deliberately not considered:
     * `fk_` is a valid table prefix and also begins every shipped literal, so treating it as proof would
     * skip the entire repair for that installation.
     *
     * @param   string  $name  Constraint name as the database reports it.
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

        return preg_match(sprintf('/_[0-9a-f]{%d}$/D', self::DIGEST_LENGTH), $name) !== 1;
    }

    /**
     * Rename every collision-prone foreign key of this installation, table by table.
     *
     * Tables carrying a longer neighbouring installation prefix are excluded even though they also begin
     * with this installation's prefix. The neighbouring prefixes come from their migration-ledger table,
     * the durable marker every initialized Kumwe installation owns. A table whose constraints are already
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
     * @throws  RuntimeException  When a constraint carries no readable name, an interrupted replay's
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

            $constraints = $manager->introspectTableByUnquotedName($tableName)->getForeignKeys();
            $present = [];
            foreach ($constraints as $constraint) {
                $present[$this->constraintName($constraint, $tableName)] = $constraint;
            }

            $statements = [];
            foreach ($constraints as $constraint) {
                $current = $this->constraintName($constraint, $tableName);
                if (!self::needsIsolation($current)) {
                    continue;
                }
                $target = self::isolatedName($tableName, $current);
                $created = $present[$target] ?? null;
                if ($created !== null && !$this->sameShape($constraint, $created)) {
                    throw new RuntimeException(sprintf(
                        'Foreign key "%s" on table "%s" cannot resume because target "%s" has a different shape.',
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
                    $constraint,
                    $created !== null,
                )];
                $present[$target] = $created ?? $this->renamed($constraint, $target);
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
     * @param   string        $tableName            Unqualified physical table name to classify.
     * @param   string        $prefix               Prefix of the installation being migrated.
     * @param   list<string>  $installationPrefixes Prefixes derived from migration ledgers in this schema.
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
            if ($candidate !== $prefix
                && str_starts_with($candidate, $prefix)
                && str_starts_with($tableName, $candidate)
            ) {
                return false;
            }
        }

        return true;
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
            if ($created) {
                return [$platform->getDropForeignKeySQL($database->quoteSingleIdentifier($current), $table)];
            }

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
     * Prove an existing replay target is the same foreign key as the source still waiting to be dropped.
     *
     * @param   ForeignKeyConstraint  $source  Shared-name constraint the migration is resuming.
     * @param   ForeignKeyConstraint  $target  Pre-existing isolated-name constraint found beside it.
     *
     * @return  bool  True only when columns, referenced table and every referential option match.
     *
     * @since   2.0.0
     */
    private function sameShape(ForeignKeyConstraint $source, ForeignKeyConstraint $target): bool
    {
        return $this->names($source->getReferencingColumnNames()) === $this->names($target->getReferencingColumnNames())
            && $source->getReferencedTableName()->toString() === $target->getReferencedTableName()->toString()
            && $this->names($source->getReferencedColumnNames()) === $this->names($target->getReferencedColumnNames())
            && $source->getMatchType() === $target->getMatchType()
            && $source->getOnUpdateAction() === $target->getOnUpdateAction()
            && $source->getOnDeleteAction() === $target->getOnDeleteAction()
            && $source->getDeferrability() === $target->getDeferrability();
    }

    /**
     * Normalize DBAL identifier objects into their exact wire names for structural comparison.
     *
     * @param   list<Name>  $names  Referencing or referenced column names in declared order.
     *
     * @return  list<string>  Identifier values in the same order.
     *
     * @since   2.0.0
     */
    private function names(array $names): array
    {
        return array_map(static fn (Name $name): string => $name->toString(), $names);
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
     *
     * @return  void
     *
     * @throws  RuntimeException  When a constraint on the table is still named collision-prone.
     *
     * @since   2.0.0
     */
    private function assertIsolated(Connection $database, string $tableName): void
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName($tableName);
        foreach ($table->getForeignKeys() as $constraint) {
            $name = $constraint->getObjectName()?->getIdentifier()->getValue() ?? '';
            if (self::needsIsolation($name)) {
                throw new RuntimeException(sprintf(
                    'Foreign key "%s" on table "%s" is still named schema-globally after the rename.',
                    $name,
                    $tableName,
                ));
            }
        }
    }
}
