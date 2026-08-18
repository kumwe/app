<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessSecurityPortalMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationPortabilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\IndexNameIsolationMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use RuntimeException;

/**
 * Drives the index-renaming repair against the configured engine, on the tables the release ships.
 *
 * A non-primary index name is schema-global on PostgreSQL, so building a second installation's
 * `organizations` table beside an installed one failed on the literal name `uniq_org_site_identifier`
 * — the collision `V2-DB-004` records. These cases build the shipped table pair under per-test
 * prefixes, isolate the first installation's names, and prove the second then builds beside it; the
 * same sequence runs on the MySQL family, where the index namespace is table-scoped, so the one
 * naming shape is asserted on every supported engine. The full-plan schema-wide proof lives in
 * `IndexNameIsolationCoexistenceIntegrationTest`.
 *
 * @since  2.0.0
 */
#[CoversClass(IndexNameIsolationMigration::class)]
final class IndexNameIsolationIntegrationTest extends TestCase
{
    /**
     * Literal indexes are renamed, and a second prefixed installation then builds beside the first.
     *
     * The first installation is created with the literal names the shipped migration writes, isolated,
     * and the second is then created — which on PostgreSQL only succeeds because the isolation freed
     * the literal names. Both installations end holding the same logical indexes under different
     * names, and every non-primary index name is unique to its installation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLiteralIndexesAreRenamedAndASecondInstallationBuildsBesideTheFirst(): void
    {
        $database = $this->database();
        $first = $this->prefix();
        $second = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $first, $created);
            $names = $this->indexNames($database, $first . 'organizations');
            self::assertContains('uniq_org_site_identifier', $names);
            self::assertContains('idx_org_site_status', $names);

            $this->isolate($database, $first);
            $isolated = $this->indexNames($database, $first . 'organizations');
            self::assertContains(
                IndexNameIsolationMigration::isolatedName($first . 'organizations', 'uniq_org_site_identifier'),
                $isolated,
            );
            self::assertContains(
                IndexNameIsolationMigration::isolatedName($first . 'organizations', 'idx_org_site_status'),
                $isolated,
            );

            $this->buildOrganizations($database, $second, $created);
            $this->isolate($database, $second);

            $secondIsolated = $this->indexNames($database, $second . 'organizations');
            foreach ([...$isolated, ...$secondIsolated] as $name) {
                self::assertFalse(IndexNameIsolationMigration::needsIsolation($name), $name);
            }
            self::assertSame([], array_intersect($isolated, $secondIsolated));
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * Running the isolation twice renames nothing the second time.
     *
     * A rename that was not idempotent would append a second digest on every upgrade until the
     * identifier limit refused it, so the no-op is asserted rather than assumed.
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
            $migration = new IndexNameIsolationMigration(new TableNames($database, $prefix));
            $migration->up($database);
            $once = $this->indexNames($database, $prefix . 'organizations');
            $migration->up($database);

            self::assertSame($once, $this->indexNames($database, $prefix . 'organizations'));
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * The rename keeps the structure it found, so a unique rule is not silently lost.
     *
     * The catalogue rename never rebuilds the index, and the fallback recreation copies the
     * introspected shape, so uniqueness, the columns and their order must be identical before and
     * after under either mechanism.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRenameKeepsTheStructureItFound(): void
    {
        $database = $this->database();
        $prefix = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $prefix, $created);
            $tableName = $prefix . 'organizations';
            $before = $this->structures($database, $tableName);
            (new IndexNameIsolationMigration(new TableNames($database, $prefix)))->up($database);
            $after = $this->structures($database, $tableName);

            self::assertSame(
                $before['uniq_org_site_identifier'],
                $after[IndexNameIsolationMigration::isolatedName($tableName, 'uniq_org_site_identifier')],
            );
            self::assertSame(
                $before['idx_org_site_status'],
                $after[IndexNameIsolationMigration::isolatedName($tableName, 'idx_org_site_status')],
            );
            self::assertStringStartsWith('UNIQUE site_identifier,identifier', $before['uniq_org_site_identifier']);
            self::assertStringStartsWith('REGULAR site_identifier,status', $before['idx_org_site_status']);
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * A target name left by an interruption resumes with only the drop the attempt still owes.
     *
     * On a platform without an index rename the repair is create-then-drop, and an attempt
     * interrupted between the two statements leaves the table holding both names. The replay has to
     * recognise the verified target as work already done and drop the shared name it still owes —
     * which is the statement that frees that name for the next installation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInterruptedFallbackAttemptIsFinishedByAReplay(): void
    {
        $database = $this->database();
        $prefix = $this->prefix();
        $created = [];

        try {
            $this->buildOrganizations($database, $prefix, $created);
            $tableName = $prefix . 'organizations';
            $target = IndexNameIsolationMigration::isolatedName($tableName, 'idx_org_site_status');
            $database->executeStatement(sprintf(
                'CREATE INDEX %s ON %s (site_identifier, status)',
                $database->quoteSingleIdentifier($target),
                $database->quoteSingleIdentifier($tableName),
            ));

            (new IndexNameIsolationMigration(new TableNames($database, $prefix)))->up($database);

            $names = $this->indexNames($database, $tableName);
            self::assertContains($target, $names);
            self::assertNotContains('idx_org_site_status', $names);
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * A target name left by an interruption is not trusted when it indexes something else.
     *
     * The replay decision is what stands between a resume and dropping a live index in favour of an
     * unrelated one. Every literal name is still present after the refusal, proving the check runs
     * before the migration issues any statement for the table.
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
            $target = IndexNameIsolationMigration::isolatedName($tableName, 'idx_org_site_status');
            $database->executeStatement(sprintf(
                'CREATE INDEX %s ON %s (status)',
                $database->quoteSingleIdentifier($target),
                $database->quoteSingleIdentifier($tableName),
            ));

            try {
                (new IndexNameIsolationMigration(new TableNames($database, $prefix)))->up($database);
                self::fail('A wrong-shape replay target must stop the migration.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('different shape', $exception->getMessage());
            }

            $names = $this->indexNames($database, $tableName);
            self::assertContains('idx_org_site_status', $names);
            self::assertContains('uniq_org_site_identifier', $names);
            self::assertContains($target, $names);
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * A parent-like prefix leaves a longer-prefix installation's tables and indexes untouched.
     *
     * Each initialized installation is identified by its own migration ledger. Without that ownership
     * marker a scan for `parent_` also matches `parent_nested_organizations` and silently migrates a
     * neighbour. The neighbour deliberately retains the shared literals here so an accidental claim
     * is observable as a changed name on every supported engine.
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

            (new IndexNameIsolationMigration(new TableNames($database, $prefix)))->up($database);

            $names = $this->indexNames($database, $neighbour . 'organizations');
            self::assertContains('uniq_org_site_identifier', $names);
            self::assertContains('idx_org_site_status', $names);
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * A rename that did not take is reported against the table it left shared, not recorded as done.
     *
     * The migration reads its own work back rather than trusting the driver, because a rename that
     * was quietly ignored would otherwise be discovered by the next installation at `CREATE INDEX`.
     * The check is pointed at a table whose indexes are still spelled literally, which is exactly the
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
            $migration = new IndexNameIsolationMigration(new TableNames($database, $prefix));
            self::assertContains('uniq_org_site_identifier', $this->indexNames($database, $tableName));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is still named schema-globally after the rename');

            (new ReflectionMethod($migration, 'assertIsolated'))->invoke($migration, $database, $tableName);
        } finally {
            $this->drop($database, $created);
        }
    }

    /**
     * Isolate one installation's index and foreign-key names, so the next installation can build.
     *
     * The foreign-key repair runs alongside because `fk_org_site` is schema-global on the MySQL
     * family: without it the second installation's table would fail at `CREATE TABLE` there before
     * ever reaching the index namespace this suite is about.
     *
     * @param   Connection  $database  Connection the installation lives on.
     * @param   string      $prefix    Table prefix of the installation being isolated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function isolate(Connection $database, string $prefix): void
    {
        $tables = new TableNames($database, $prefix);
        (new IndexNameIsolationMigration($tables))->up($database);
        (new ConstraintNameIsolationPortabilityMigration($tables))->up($database);
    }

    /**
     * Build the `sites` and `organizations` pair the shipped migration declares, under one prefix.
     *
     * The organization table is taken from `BusinessSecurityPortalMigration` itself rather than
     * restated, so the literal names under test — `uniq_org_site_identifier`, `idx_org_site_status`
     * and `fk_org_site` — are the ones the release actually writes.
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
     * List the non-primary index names one table carries, in a stable order.
     *
     * @param   Connection        $database   Connection the table is introspected on.
     * @param   non-empty-string  $tableName  Prefixed physical table name.
     *
     * @return  list<string>  Index names, sorted.
     *
     * @since   2.0.0
     */
    private function indexNames(Connection $database, string $tableName): array
    {
        $names = array_keys($this->structures($database, $tableName));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Read every non-primary index's structure back from the live schema, keyed by its name.
     *
     * @param   Connection        $database   Connection the table is introspected on.
     * @param   non-empty-string  $tableName  Prefixed physical table name.
     *
     * @return  array<string, string>  Index name to its type, ordered columns and predicate.
     *
     * @since   2.0.0
     */
    private function structures(Connection $database, string $tableName): array
    {
        $structures = [];
        $table = $database->createSchemaManager()->introspectTableByUnquotedName($tableName);
        foreach ($table->getIndexes() as $index) {
            if ($this->isPrimary($index)) {
                continue;
            }
            $columns = [];
            foreach ($index->getIndexedColumns() as $column) {
                $columns[] = $column->getColumnName()->getIdentifier()->getValue();
            }
            $structures[$index->getObjectName()->getIdentifier()->getValue()] = sprintf(
                '%s %s %s',
                $index->getType()->name,
                implode(',', $columns),
                $index->getPredicate() ?? '-',
            );
        }

        return $structures;
    }

    /**
     * Report whether one introspected index backs the table's primary key.
     *
     * DBAL reports the primary key's backing index under the portable name `primary` on every
     * supported engine, which is also a name the real index cannot legally carry on PostgreSQL.
     *
     * @param   Index  $index  Index as introspected from the live schema.
     *
     * @return  bool  True when the index is the primary key's backing index.
     *
     * @since   2.0.0
     */
    private function isPrimary(Index $index): bool
    {
        return strtolower($index->getObjectName()->getIdentifier()->getValue()) === 'primary';
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
     * millisecond timestamp, so two minted in the same millisecond — which is what happens when one
     * case builds two installations — would share their leading characters and collide with each
     * other.
     *
     * @return  non-empty-string  Valid table prefix unique to this installation.
     *
     * @since   2.0.0
     */
    private function prefix(): string
    {
        return 'x' . substr(str_replace('-', '', Uuid::uuid7()->toString()), -10) . '_';
    }

    /**
     * Open the configured MariaDB, MySQL or PostgreSQL connection for the focused schema proof.
     *
     * The MySQL family scopes an index name to its table and never had the collision, but it executes
     * this migration too, so the same portable postcondition is asserted on every supported engine.
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
