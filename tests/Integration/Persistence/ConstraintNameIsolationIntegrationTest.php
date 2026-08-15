<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessSecurityPortalMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;

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
