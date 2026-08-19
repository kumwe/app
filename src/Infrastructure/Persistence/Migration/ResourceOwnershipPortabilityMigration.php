<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Pins the resource-ownership table to the canonical site identifier and names its constraint per table.
 *
 * `resource_site_ownership.site_identifier` was declared as a bare bounded string, so nothing tied its
 * physical character definition to `sites.identifier` — the column every ownership lookup, including the
 * scheduler's dispatch claim, compares it against. Where the two resolve differently a MySQL-family engine
 * refuses the comparison outright with error 1267 and PostgreSQL refuses to choose a collation, and the
 * only thing holding them together today is a foreign key that a partial-schema recovery may never have
 * created. `BusinessSecurityPortalMigration` already reproduces the canonical definition on every column it
 * adds for precisely this reason; this brings the ownership column, which predates that rule, under it.
 *
 * The repair is expressed as schema differences rather than engine-specific DDL, which is what makes it
 * portable: the canonical character definition is copied only where the platform publishes one, so MariaDB
 * and MySQL receive a column alteration while PostgreSQL, which publishes neither for these columns,
 * produces an empty difference and executes nothing. The constraint is dropped before the column changes
 * because a MySQL-family engine refuses to alter a column a foreign key still binds, and is recreated under
 * the name `ApplicationAuthorizationMigrationRecovery` already derives, so the two paths that create this
 * constraint stop disagreeing about what it is called.
 *
 * @since  2.0.0
 */
final readonly class ResourceOwnershipPortabilityMigration implements RepeatableMigration
{
    /**
     * Stable ordered migration identity, appended after the extension supply-chain migration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260815010000_resource_ownership_portability';

    /**
     * Constraint-name stem shared by the published migration and its recovery path.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string FOREIGN_KEY_STEM = 'fk_resource_site';

    /**
     * Bind the repair to the installation's physical table names.
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
     * Bind migration compatibility to the exact repair implementation.
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
            throw new RuntimeException('The resource ownership portability checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Compile the per-installation constraint name both creation paths settle on.
     *
     * The derivation is deliberately identical to the one `ApplicationAuthorizationMigrationRecovery`
     * performs, because a name the two paths compute differently is the defect rather than the fix.
     * Hashing the prefixed physical table name is what keeps the name unique to one installation.
     *
     * @param   string  $ownershipName  Prefixed physical name of the ownership table.
     *
     * @return  string  Constraint name unique to this installation's ownership table.
     *
     * @since   2.0.0
     */
    public static function foreignKeyName(string $ownershipName): string
    {
        return self::FOREIGN_KEY_STEM . '_' . substr(hash('sha256', $ownershipName), 0, 16);
    }

    /**
     * Give the ownership column the canonical character definition and its constraint a per-table name.
     *
     * A schema that already satisfies both properties is left untouched and executes no statement, which
     * is what lets an interrupted attempt be replayed in full. Where a repair is needed the constraint is
     * released first, the column is altered second and the constraint is recreated last, because a
     * MySQL-family engine refuses to alter a column while a foreign key still binds it.
     *
     * @param   Connection  $database  Installation database whose ownership table is repaired.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the canonical site identifier is not a bounded string, or the repair
     *          leaves the two columns still disagreeing about their character definition.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $ownershipName = $this->tables->raw('resource_site_ownership');
        $canonical = $this->canonicalColumn($database);
        $before = $database->createSchemaManager()->introspectTableByUnquotedName($ownershipName);
        $constraints = array_keys($before->getForeignKeys());
        $target = self::foreignKeyName($ownershipName);
        if ($this->matches($canonical, $before->getColumn('site_identifier')) && $constraints === [$target]) {
            return;
        }

        $released = clone $before;
        foreach ($constraints as $constraint) {
            $released->dropForeignKey($constraint);
        }
        $this->apply($database, $before, $released);

        $current = $database->createSchemaManager()->introspectTableByUnquotedName($ownershipName);
        $repaired = clone $current;
        $this->copyCharacterDefinition($canonical, $repaired->getColumn('site_identifier'));
        $this->apply($database, $current, $repaired);

        $unconstrained = $database->createSchemaManager()->introspectTableByUnquotedName($ownershipName);
        $constrained = clone $unconstrained;
        $constrained->addForeignKeyConstraint(
            $this->tables->raw('sites'),
            ['site_identifier'],
            ['identifier'],
            ['onDelete' => 'CASCADE'],
            $target,
        );
        $this->apply($database, $unconstrained, $constrained);

        $this->assertPinned($database, $ownershipName);
    }

    /**
     * Read the canonical site identifier every referencing column copies its definition from.
     *
     * @param   Connection  $database  Database the canonical column is introspected on.
     *
     * @return  Column  Introspected `sites.identifier` column.
     *
     * @throws  RuntimeException  When that column is not a bounded string.
     *
     * @since   2.0.0
     */
    private function canonicalColumn(Connection $database): Column
    {
        $column = $database->createSchemaManager()
            ->introspectTableByUnquotedName($this->tables->raw('sites'))
            ->getColumn('identifier');
        $length = $column->getLength();
        if (!is_int($length) || $length < 1) {
            throw new RuntimeException('The canonical site identifier column is incompatible.');
        }

        return $column;
    }

    /**
     * Copy the canonical column's character definition onto the referencing column, where one exists.
     *
     * Nothing is copied on a platform that publishes neither a character set nor a collation for these
     * columns, which is how one code path stays correct on PostgreSQL without a platform test: the
     * comparison that follows sees no change and emits no statement.
     *
     * @param   Column  $source  Canonical `sites.identifier` column supplying the definition.
     * @param   Column  $target  Ownership `site_identifier` column receiving it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function copyCharacterDefinition(Column $source, Column $target): void
    {
        $charset = $source->getCharset();
        if ($charset !== null) {
            $target->setPlatformOption('charset', $charset);
        }
        $collation = $source->getCollation();
        if ($collation !== null) {
            $target->setPlatformOption('collation', $collation);
        }
    }

    /**
     * Report whether two columns already share one physical character definition.
     *
     * @param   Column  $canonical    Column the definition is owned by.
     * @param   Column  $referencing  Column compared against it.
     *
     * @return  bool  True when character set and collation both agree, including where neither exists.
     *
     * @since   2.0.0
     */
    private function matches(Column $canonical, Column $referencing): bool
    {
        return $canonical->getCharset() === $referencing->getCharset()
            && $canonical->getCollation() === $referencing->getCollation();
    }

    /**
     * Execute one table-local difference without exposing unrelated introspection noise.
     *
     * @param   Connection  $database  Database executing the generated portable DDL.
     * @param   Table       $before    Table as introspected.
     * @param   Table       $after     Cloned table carrying the intended change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function apply(Connection $database, Table $before, Table $after): void
    {
        $difference = $database->createSchemaManager()->createComparator()->compareTables($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterTableSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Prove the two compared columns now share one physical character definition.
     *
     * This is the postcondition every ownership lookup depends on, and it is asserted rather than assumed
     * because the foreign key enforces it only on MySQL-family engines, and only where it was created.
     *
     * @param   Connection        $database       Database the repaired schema is read back from.
     * @param   non-empty-string  $ownershipName  Prefixed physical name of the ownership table.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the ownership column still disagrees with the canonical column.
     *
     * @since   2.0.0
     */
    private function assertPinned(Connection $database, string $ownershipName): void
    {
        $manager = $database->createSchemaManager();
        $canonical = $manager
            ->introspectTableByUnquotedName($this->tables->raw('sites'))
            ->getColumn('identifier');
        $referencing = $manager
            ->introspectTableByUnquotedName($ownershipName)
            ->getColumn('site_identifier');
        if (!$this->matches($canonical, $referencing)) {
            throw new RuntimeException('The resource ownership site identifier has an incompatible character set.');
        }
    }
}
