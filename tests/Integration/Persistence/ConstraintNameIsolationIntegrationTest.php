<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessSecurityPortalMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use RuntimeException;

/**
 * Proves two prefixed Kumwe installations can live in one schema, which a shared name made impossible.
 *
 * A foreign-key constraint name is schema-global on MySQL and MariaDB. Building a second installation's
 * `organizations` table beside an installed one therefore failed on the literal name `fk_org_site`, which
 * is the errno 121 that `MigrationIntegrationTest` records. These drive the real migrations against the
 * configured engine: the first installation is built and its names isolated, the second is then built in
 * the same schema, and both are asserted to hold the same logical constraint under different names.
 *
 * @since  2.0.0
 */
#[CoversClass(ConstraintNameIsolationMigration::class)]
final class ConstraintNameIsolationIntegrationTest extends TestCase
{
    /**
     * A second prefixed installation builds beside an isolated first one, in the same schema.
     *
     * This is the acceptance test the finding asks for, reduced to the two tables that reproduce it. The
     * first installation is created with the literal name the shipped migration writes, isolated, and the
     * second is then created — which only succeeds because the isolation freed the literal name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASecondPrefixedInstallationBuildsBesideAnIsolatedFirstOne(): void
    {
        $database = $this->database();
        $first = $this->prefix();
        $second = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $first, $created);
            self::assertSame(
                ['fk_org_site'],
                $this->constraintNames($database, $first . 'organizations'),
                'The shipped migration writes the literal name this repair exists to free.',
            );

            (new ConstraintNameIsolationMigration(new TableNames($database, $first)))->up($database);
            $isolated = $this->constraintNames($database, $first . 'organizations');
            self::assertSame(
                [ConstraintNameIsolationMigration::isolatedName($first . 'organizations', 'fk_org_site')],
                $isolated,
            );

            $this->buildOrganizations($database, $second, $created);
            (new ConstraintNameIsolationMigration(new TableNames($database, $second)))->up($database);

            self::assertSame(
                [ConstraintNameIsolationMigration::isolatedName($second . 'organizations', 'fk_org_site')],
                $this->constraintNames($database, $second . 'organizations'),
            );
            self::assertNotSame($isolated, $this->constraintNames($database, $second . 'organizations'));
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * Running the isolation twice renames nothing the second time.
     *
     * A rename that was not idempotent would append a second digest on every upgrade until the identifier
     * limit refused it, so the no-op is asserted rather than assumed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRunningTheIsolationTwiceIsANoOperation(): void
    {
        $database = $this->database();
        $prefix = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $prefix, $created);
            $migration = new ConstraintNameIsolationMigration(new TableNames($database, $prefix));
            $migration->up($database);
            $once = $this->constraintNames($database, $prefix . 'organizations');
            $migration->up($database);

            self::assertSame($once, $this->constraintNames($database, $prefix . 'organizations'));
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * The rename carries the referential action across, so cascade behaviour is not silently lost.
     *
     * The MySQL family has no rename for a foreign key, so the constraint is dropped and recreated. That
     * is the path on which a referential action can go missing, and this is what proves it does not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRenameKeepsTheReferentialActionItFound(): void
    {
        $database = $this->database();
        $prefix = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $prefix, $created);
            $before = $this->onDelete($database, $prefix . 'organizations');
            (new ConstraintNameIsolationMigration(new TableNames($database, $prefix)))->up($database);

            self::assertSame('RESTRICT', $before);
            self::assertSame($before, $this->onDelete($database, $prefix . 'organizations'));
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * An attempt interrupted between the create and the drop finishes the job when it is replayed.
     *
     * This is the scenario the create-then-drop order exists for, driven against the engine where DDL
     * commits implicitly and an interruption therefore leaves the first statement applied. The first
     * statement is run on its own and the migration is then replayed over the half-renamed table: it has
     * to recognise the isolated name as work already done, skip the create that would now fail on a
     * duplicate, and drop the shared name it still owes — which is the statement that frees that name
     * for the next installation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAttemptInterruptedAfterTheCreateIsFinishedByAReplay(): void
    {
        $database = $this->database();
        $prefix = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $prefix, $created);
            $tableName = $prefix . 'organizations';
            $migration = new ConstraintNameIsolationMigration(new TableNames($database, $prefix));
            $target = ConstraintNameIsolationMigration::isolatedName($tableName, 'fk_org_site');
            /** @var list<string> $statements */
            $statements = (new ReflectionMethod($migration, 'renameStatements'))->invoke(
                $migration,
                $database,
                $tableName,
                'fk_org_site',
                $target,
                $this->constraint($database, $tableName, 'fk_org_site'),
                false,
            );
            self::assertCount(2, $statements, 'The MySQL family rebuilds the constraint under its new name.');

            $database->executeStatement($statements[0]);
            $interrupted = ['fk_org_site', $target];
            sort($interrupted, SORT_STRING);
            self::assertSame($interrupted, $this->constraintNames($database, $tableName));

            $migration->up($database);

            self::assertSame([$target], $this->constraintNames($database, $tableName));
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * A rename that did not take is reported against the table it left shared, not recorded as done.
     *
     * The migration reads its own work back rather than trusting the driver, because a rename that was
     * quietly ignored would otherwise be discovered by the next installation at `CREATE TABLE`. The
     * check is pointed at a table whose constraint is still spelled literally, which is exactly the
     * state a silently ignored rename would leave behind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATableLeftWithASharedNameIsReportedRatherThanAccepted(): void
    {
        $database = $this->database();
        $prefix = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $prefix, $created);
            $tableName = $prefix . 'organizations';
            $migration = new ConstraintNameIsolationMigration(new TableNames($database, $prefix));
            self::assertSame(['fk_org_site'], $this->constraintNames($database, $tableName));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is still named schema-globally after the rename');

            (new ReflectionMethod($migration, 'assertIsolated'))->invoke($migration, $database, $tableName, $prefix);
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * Read one named foreign key back from the live schema.
     *
     * @param   Connection        $database   Connection the table is introspected on.
     * @param   non-empty-string  $tableName  Prefixed physical table name.
     * @param   string            $name       Constraint name to select.
     *
     * @return  ForeignKeyConstraint  The constraint as introspection reports it.
     *
     * @since   2.0.0
     */
    private function constraint(Connection $database, string $tableName, string $name): ForeignKeyConstraint
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName($tableName);
        foreach ($table->getForeignKeys() as $constraint) {
            if ($constraint->getObjectName()?->getIdentifier()->getValue() === $name) {
                return $constraint;
            }
        }

        self::fail(sprintf('Table %s carries no foreign key named %s.', $tableName, $name));
    }

    /**
     * Build the `sites` and `organizations` pair the shipped migration declares, under one prefix.
     *
     * The organization table is taken from `BusinessSecurityPortalMigration` itself rather than restated,
     * so the literal name under test is the one the release actually writes.
     *
     * @param   Connection     $database  Connection the tables are created on.
     * @param   string         $prefix    Table prefix of the installation being built.
     * @param   list<string>   $created   Physical names created so far, appended to for cleanup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function buildOrganizations(Connection $database, string $prefix, array &$created): void
    {
        $tables = new TableNames($database, $prefix);
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (identifier VARCHAR(191) NOT NULL PRIMARY KEY)',
            $tables->quoted('sites'),
        ));
        $created[] = $tables->raw('sites');

        $manager = $database->createSchemaManager();
        $migration = new BusinessSecurityPortalMigration($tables);
        $identifier = $manager->introspectTableByUnquotedName($tables->raw('sites'))->getColumn('identifier');
        /** @var array<string, mixed> $options */
        $options = (new ReflectionMethod($migration, 'siteIdentifierOptions'))->invoke($migration, $identifier);
        /** @var list<Table> $definitions */
        $definitions = (new ReflectionMethod($migration, 'tables'))->invoke($migration, $options);
        foreach ($definitions as $definition) {
            if ($definition->getObjectName()->getUnqualifiedName()->getValue() !== $tables->raw('organizations')) {
                continue;
            }
            $manager->createTable($definition);
            $created[] = $tables->raw('organizations');

            return;
        }

        self::fail('The security portal migration no longer declares an organizations table.');
    }

    /**
     * List the foreign-key names one table carries, in a stable order.
     *
     * @param   Connection        $database   Connection the table is introspected on.
     * @param   non-empty-string  $tableName  Prefixed physical table name.
     *
     * @return  list<string>  Constraint names, sorted.
     *
     * @since   2.0.0
     */
    private function constraintNames(Connection $database, string $tableName): array
    {
        $names = [];
        $table = $database->createSchemaManager()->introspectTableByUnquotedName($tableName);
        foreach ($table->getForeignKeys() as $constraint) {
            $names[] = $constraint->getObjectName()?->getIdentifier()->getValue() ?? '';
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Read the delete action of the one foreign key a table carries.
     *
     * @param   Connection        $database   Connection the table is introspected on.
     * @param   non-empty-string  $tableName  Prefixed physical table name.
     *
     * @return  string  Referential action for delete, such as `RESTRICT`.
     *
     * @since   2.0.0
     */
    private function onDelete(Connection $database, string $tableName): string
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName($tableName);
        foreach ($table->getForeignKeys() as $constraint) {
            return $constraint->getOnDeleteAction()->value;
        }

        self::fail(sprintf('Table %s carries no foreign key.', $tableName));
    }

    /**
     * Remove the tables one case created, referencing tables first.
     *
     * @param   Connection    $database  Connection the tables are dropped on.
     * @param   list<string>  $created   Physical names created, in creation order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function drop(Connection $database, array $created): void
    {
        foreach (array_reverse($created) as $name) {
            $database->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s',
                $database->quoteSingleIdentifier($name),
            ));
        }
    }

    /**
     * Mint a prefix no other installation, run or case in this suite is using.
     *
     * The randomness has to come from the tail of the identifier: a version 7 UUID opens with a
     * millisecond timestamp, so two minted in the same millisecond — which is what happens when one case
     * builds two installations — would share their leading characters and collide with each other.
     *
     * @return  non-empty-string  Valid table prefix unique to this installation.
     *
     * @since   2.0.0
     */
    private function prefix(): string
    {
        return 'c' . substr(str_replace('-', '', Uuid::uuid7()->toString()), -10) . '_';
    }

    /**
     * Open the configured connection, skipping where the collision this proves cannot occur.
     *
     * PostgreSQL scopes a constraint name to its table and never had the collision, so the acceptance
     * proof belongs to the MySQL family; the rename runs on PostgreSQL too and is covered there by the
     * ordinary migration run.
     *
     * @return  Connection  Connection to the configured test database.
     *
     * @since   2.0.0
     */
    private function database(): Connection
    {
        $database = TestKernelFactory::create(Environment::fromGlobals())->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('A foreign key name is schema-global only on the MySQL family.');
        }

        return $database;
    }
}
