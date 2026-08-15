<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Table;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigrationRecovery;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessSecurityPortalMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationCompatibilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationPortabilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DoctrineNonTransactionalMigrationRecovery;
use Kumwe\CMS\Infrastructure\Persistence\Migration\NonTransactionalMigrationAction;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use RuntimeException;

/**
 * Proves the focused foreign-key isolation repair against every configured database engine.
 *
 * A foreign-key constraint name is schema-global on MySQL and MariaDB. Building a second installation's
 * `organizations` table beside an installed one therefore failed on the literal name `fk_org_site`, which
 * is the errno 121 that `MigrationIntegrationTest` records. These drive the real migration against the
 * configured MariaDB, MySQL or PostgreSQL job using the two-table reproduction: the first installation is
 * built and its names isolated, the second is then built in the same schema, and both are asserted to hold
 * the same logical constraint under different names. This is focused evidence, not a full-plan install.
 *
 * @since  2.0.0
 */
#[CoversClass(ConstraintNameIsolationPortabilityMigration::class)]
#[CoversClass(ConstraintNameIsolationCompatibilityMigration::class)]
#[CoversClass(DoctrineNonTransactionalMigrationRecovery::class)]
final class ConstraintNameIsolationPortabilityIntegrationTest extends TestCase
{
    /**
     * A fresh database executes the corrected implementation in the published plan slot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFreshCompatibilitySlotExecutesTheSafeImplementation(): void
    {
        $database = $this->database();
        $prefix = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $prefix, $created);
            $migration = new ConstraintNameIsolationCompatibilityMigration(
                new TableNames($database, $prefix),
            );
            $migration->up($database);

            self::assertSame(
                [ConstraintNameIsolationPortabilityMigration::isolatedName(
                    $prefix . 'organizations',
                    'fk_org_site',
                )],
                $this->constraintNames($database, $prefix . 'organizations'),
            );
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * An old-checksum MySQL attempt resumes through the corrected same-ID implementation.
     *
     * The journal is opened with the immutable published migration, then the first create-before-drop
     * statement is applied to reproduce an implicit-commit interruption. Recovery accepts only that
     * published checksum, the wrapper validates the target shape and removes the shared source, and the
     * old attempt can then be retired against the new implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInterruptedPublishedAttemptResumesThroughCompatibilitySlot(): void
    {
        $database = $this->database();
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Only the MySQL family journals implicit-commit DDL attempts.');
        }
        $prefix = $this->prefix();
        $created = [];
        $tables = new TableNames($database, $prefix);
        $published = new ConstraintNameIsolationMigration($tables);
        $compatibility = new ConstraintNameIsolationCompatibilityMigration($tables);
        $recovery = new DoctrineNonTransactionalMigrationRecovery(
            $database,
            $tables,
            new ApplicationAuthorizationMigrationRecovery($database, $tables),
        );

        try {
            $this->buildOrganizations($database, $prefix, $created);
            self::assertSame(NonTransactionalMigrationAction::Execute, $recovery->prepare($published));
            $created[] = $tables->raw('migration_attempts');

            $tableName = $tables->raw('organizations');
            $target = ConstraintNameIsolationPortabilityMigration::isolatedName($tableName, 'fk_org_site');
            /** @var list<string> $statements */
            $statements = (new ReflectionMethod($published, 'renameStatements'))->invoke(
                $published,
                $database,
                $tableName,
                'fk_org_site',
                $target,
                $this->constraint($database, $tableName, 'fk_org_site'),
                false,
            );
            self::assertCount(2, $statements);
            $database->executeStatement($statements[0]);

            self::assertSame(NonTransactionalMigrationAction::Execute, $recovery->prepare($compatibility));
            $compatibility->up($database);
            $recovery->complete($compatibility);

            self::assertSame([$target], $this->constraintNames($database, $tableName));
            self::assertSame('0', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('migration_attempts'),
            )));
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * An old-checksum attempt still refuses a partial target that protects a different relationship.
     *
     * Recovery first accepts the exact published checksum and returns control to the same-ID wrapper.
     * The wrapper must then fail before dropping either constraint, proving that the compatibility path
     * does not turn the historical-checksum exception into trust in an arbitrary pre-existing target.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInterruptedPublishedAttemptRefusesAWrongShapeTarget(): void
    {
        $database = $this->database();
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Only the MySQL family journals implicit-commit DDL attempts.');
        }
        $prefix = $this->prefix();
        $created = [];
        $tables = new TableNames($database, $prefix);
        $published = new ConstraintNameIsolationMigration($tables);
        $compatibility = new ConstraintNameIsolationCompatibilityMigration($tables);
        $recovery = new DoctrineNonTransactionalMigrationRecovery(
            $database,
            $tables,
            new ApplicationAuthorizationMigrationRecovery($database, $tables),
        );

        try {
            $this->buildOrganizations($database, $prefix, $created);
            self::assertSame(NonTransactionalMigrationAction::Execute, $recovery->prepare($published));
            $created[] = $tables->raw('migration_attempts');

            $tableName = $tables->raw('organizations');
            $source = $this->constraint($database, $tableName, 'fk_org_site');
            $target = ConstraintNameIsolationPortabilityMigration::isolatedName($tableName, 'fk_org_site');
            $database->executeStatement($database->getDatabasePlatform()->getCreateForeignKeySQL(
                $this->wrongShapeTarget($source, $target),
                $database->quoteSingleIdentifier($tableName),
            ));

            self::assertSame(NonTransactionalMigrationAction::Execute, $recovery->prepare($compatibility));
            try {
                $compatibility->up($database);
                self::fail('The compatibility path must refuse a wrong-shape replay target.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('different shape', $exception->getMessage());
            }

            $expected = ['fk_org_site', $target];
            sort($expected, SORT_STRING);
            self::assertSame($expected, $this->constraintNames($database, $tableName));
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * The compatibility exception does not accept an arbitrary checksum under the published ID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInterruptedCompatibilityRefusesAnyOtherChecksum(): void
    {
        $database = $this->database();
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Only the MySQL family journals implicit-commit DDL attempts.');
        }
        $prefix = $this->prefix();
        $tables = new TableNames($database, $prefix);
        $created = [];
        $recovery = new DoctrineNonTransactionalMigrationRecovery(
            $database,
            $tables,
            new ApplicationAuthorizationMigrationRecovery($database, $tables),
        );

        try {
            self::assertSame(
                NonTransactionalMigrationAction::Execute,
                $recovery->prepare(new ConstraintNameIsolationMigration($tables)),
            );
            $created[] = $tables->raw('migration_attempts');
            $database->update(
                $tables->raw('migration_attempts'),
                ['checksum' => str_repeat('0', 64)],
                ['version' => ConstraintNameIsolationCompatibilityMigration::ID],
            );

            try {
                $recovery->prepare(new ConstraintNameIsolationCompatibilityMigration($tables));
                self::fail('An unrecognized interrupted checksum must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('checksum drift', $exception->getMessage());
            }
        } finally {
            $this->drop($database, $created);
        }
    }

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

            (new ConstraintNameIsolationPortabilityMigration(new TableNames($database, $first)))->up($database);
            $isolated = $this->constraintNames($database, $first . 'organizations');
            self::assertSame(
                [ConstraintNameIsolationPortabilityMigration::isolatedName($first . 'organizations', 'fk_org_site')],
                $isolated,
            );

            $this->buildOrganizations($database, $second, $created);
            (new ConstraintNameIsolationPortabilityMigration(new TableNames($database, $second)))->up($database);

            self::assertSame(
                [ConstraintNameIsolationPortabilityMigration::isolatedName($second . 'organizations', 'fk_org_site')],
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
            $migration = new ConstraintNameIsolationPortabilityMigration(new TableNames($database, $prefix));
            $migration->up($database);
            $once = $this->constraintNames($database, $prefix . 'organizations');
            $migration->up($database);

            self::assertSame($once, $this->constraintNames($database, $prefix . 'organizations'));
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * The valid `fk_` table prefix does not make every shipped literal look pre-isolated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testForeignKeyTablePrefixStillRenamesTheLiteralConstraint(): void
    {
        $database = $this->database();
        $prefix = 'fk_';
        $created = [];

        try {
            $this->buildOrganizations($database, $prefix, $created);
            $migration = new ConstraintNameIsolationPortabilityMigration(new TableNames($database, $prefix));
            $migration->up($database);

            self::assertSame(
                [ConstraintNameIsolationPortabilityMigration::isolatedName('fk_organizations', 'fk_org_site')],
                $this->constraintNames($database, 'fk_organizations'),
            );
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * A parent-like prefix leaves a longer-prefix installation's tables and constraints untouched.
     *
     * Each initialized installation is identified by its own migration ledger. Without that ownership
     * marker a scan for `parent_` also matches `parent_nested_organizations` and silently migrates a
     * neighbour. The neighbour deliberately retains the shared literal here so an accidental claim is
     * observable as a changed name on every supported engine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOverlappingPrefixDoesNotClaimAnInitializedNeighbour(): void
    {
        $database = $this->database();
        $prefix = $this->prefix();
        $neighbour = $prefix . 'nested_';
        $created = [];

        try {
            $this->buildOrganizations($database, $neighbour, $created);
            $this->buildLedger($database, $prefix, $created);
            $this->buildLedger($database, $neighbour, $created);

            (new ConstraintNameIsolationCompatibilityMigration(new TableNames($database, $prefix)))->up($database);

            self::assertSame(
                ['fk_org_site'],
                $this->constraintNames($database, $neighbour . 'organizations'),
            );
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
            (new ConstraintNameIsolationPortabilityMigration(new TableNames($database, $prefix)))->up($database);

            self::assertNotSame('', $before);
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
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Only the MySQL family rebuilds a foreign key with two implicit-commit steps.');
        }
        $prefix = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $prefix, $created);
            $tableName = $prefix . 'organizations';
            $migration = new ConstraintNameIsolationPortabilityMigration(new TableNames($database, $prefix));
            $target = ConstraintNameIsolationPortabilityMigration::isolatedName($tableName, 'fk_org_site');
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
     * A target name left by an interruption is not trusted when it protects a different relationship.
     *
     * The replay decision is security-sensitive: dropping the source merely because the target name
     * exists could replace the intended relationship with an unrelated constraint. Both names remain
     * present after the refusal, proving the check runs before the migration issues either rename step.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReplayRefusesAnExistingTargetWithTheWrongShape(): void
    {
        $database = $this->database();
        $prefix = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $prefix, $created);
            $tableName = $prefix . 'organizations';
            $migration = new ConstraintNameIsolationPortabilityMigration(new TableNames($database, $prefix));
            $source = $this->constraint($database, $tableName, 'fk_org_site');
            $target = ConstraintNameIsolationPortabilityMigration::isolatedName($tableName, 'fk_org_site');
            $database->executeStatement($database->getDatabasePlatform()->getCreateForeignKeySQL(
                $this->wrongShapeTarget($source, $target),
                $database->quoteSingleIdentifier($tableName),
            ));

            try {
                $migration->up($database);
                self::fail('A wrong-shape replay target must stop the migration.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('target', $exception->getMessage());
                self::assertStringContainsString('different shape', $exception->getMessage());
            }

            $expected = ['fk_org_site', $target];
            sort($expected, SORT_STRING);
            self::assertSame($expected, $this->constraintNames($database, $tableName));
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
            $migration = new ConstraintNameIsolationPortabilityMigration(new TableNames($database, $prefix));
            self::assertSame(['fk_org_site'], $this->constraintNames($database, $tableName));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is still named schema-globally after the rename');

            (new ReflectionMethod($migration, 'assertIsolated'))->invoke($migration, $database, $tableName);
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
     * Build a target with the source relationship but a deliberately different delete action.
     *
     * @param   ForeignKeyConstraint  $source  Shared-name source whose shape is otherwise copied.
     * @param   non-empty-string      $target  Derived name the replay would otherwise trust.
     *
     * @return  ForeignKeyConstraint  Same relationship under the target name, with one mismatched option.
     *
     * @since   2.0.0
     */
    private function wrongShapeTarget(
        ForeignKeyConstraint $source,
        string $target,
    ): ForeignKeyConstraint {
        $onDelete = $source->getOnDeleteAction() === ReferentialAction::CASCADE
            ? ReferentialAction::RESTRICT
            : ReferentialAction::CASCADE;

        return ForeignKeyConstraint::editor()
            ->setUnquotedName($target)
            ->setReferencingColumnNames(...$source->getReferencingColumnNames())
            ->setReferencedTableName($source->getReferencedTableName())
            ->setReferencedColumnNames(...$source->getReferencedColumnNames())
            ->setMatchType($source->getMatchType())
            ->setOnUpdateAction($source->getOnUpdateAction())
            ->setOnDeleteAction($onDelete)
            ->setDeferrability($source->getDeferrability())
            ->create();
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

            // This fixture isolates the shipped foreign-key definition. PostgreSQL's separate schema-global
            // index-name limitation is tracked independently, so the two otherwise unrelated indexes must not
            // prevent this focused table pair from reaching the foreign-key migration under test.
            $definition->dropIndex('uniq_org_site_identifier');
            $definition->addUniqueIndex(
                ['site_identifier', 'identifier'],
                ConstraintNameIsolationPortabilityMigration::isolatedName(
                    $tables->raw('organizations'),
                    'uniq_org_site_identifier',
                ),
            );
            $definition->dropIndex('idx_org_site_status');
            $definition->addIndex(
                ['site_identifier', 'status'],
                ConstraintNameIsolationPortabilityMigration::isolatedName(
                    $tables->raw('organizations'),
                    'idx_org_site_status',
                ),
            );
            $manager->createTable($definition);
            $created[] = $tables->raw('organizations');

            return;
        }

        self::fail('The security portal migration no longer declares an organizations table.');
    }

    /**
     * Create the durable table marker used to distinguish initialized installations in one schema.
     *
     * @param   Connection    $database  Connection the ledger is created on.
     * @param   string        $prefix    Prefix whose installation owns the ledger.
     * @param   list<string>  $created   Physical names created so far, appended to for cleanup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function buildLedger(Connection $database, string $prefix, array &$created): void
    {
        $tables = new TableNames($database, $prefix);
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (id VARCHAR(191) NOT NULL PRIMARY KEY)',
            $tables->quoted('schema_migrations'),
        ));
        $created[] = $tables->raw('schema_migrations');
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
     * Open the configured MariaDB, MySQL or PostgreSQL connection for the focused schema proof.
     *
     * PostgreSQL scopes a constraint name to its table and never had the schema-global collision, but it
     * still executes this migration so the same portable postcondition is asserted on every supported job.
     *
     * @return  Connection  Connection to the configured test database.
     *
     * @since   2.0.0
     */
    private function database(): Connection
    {
        $database = TestKernelFactory::create(Environment::fromGlobals())->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);

        return $database;
    }
}
