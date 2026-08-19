<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Kumwe\App\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ConstraintNameIsolationPortabilityMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\TranslationGroupSiteOwnershipMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the append-only portability correction without changing the published isolation migration.
 *
 * @since  2.0.0
 */
#[CoversClass(ConstraintNameIsolationPortabilityMigration::class)]
final class ConstraintNameIsolationPortabilityMigrationTest extends TestCase
{
    /**
     * The new ledger identity follows the latest published migration and binds its own bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIdentityAndChecksumAreAppendOnly(): void
    {
        $migration = $this->migration();

        self::assertSame('20260820010000_constraint_name_isolation_portability', $migration->id());
        self::assertGreaterThan(TranslationGroupSiteOwnershipMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        self::assertSame($migration->checksum(), $this->migration()->checksum());
        $source = (new ReflectionClass($migration))->getFileName();
        self::assertIsString($source);
        $original = dirname($source) . '/ConstraintNameIsolationMigration.php';
        $sourceDigest = hash_file('sha256', $source);
        $originalDigest = hash_file('sha256', $original);
        self::assertIsString($sourceDigest);
        self::assertIsString($originalDigest);
        self::assertSame(
            hash('sha256', $migration->id() . ':' . $sourceDigest . ':' . $originalDigest),
            $migration->checksum(),
        );
    }

    /**
     * The follow-up resumes the exact target names the published migration may already have created.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTargetDerivationMatchesThePublishedMigration(): void
    {
        self::assertSame(
            ConstraintNameIsolationMigration::isolatedName('kumwe_organizations', 'fk_org_site'),
            ConstraintNameIsolationPortabilityMigration::isolatedName('kumwe_organizations', 'fk_org_site'),
        );
        self::assertSame(
            ConstraintNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES,
            ConstraintNameIsolationPortabilityMigration::MAXIMUM_IDENTIFIER_BYTES,
        );
    }

    /**
     * A valid `fk_` installation prefix cannot hide a shared literal from the follow-up.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyDigestSuffixedNamesAreAlreadyIsolated(): void
    {
        $isolated = ConstraintNameIsolationPortabilityMigration::isolatedName(
            'fk_organizations',
            'fk_org_site',
        );

        self::assertTrue(ConstraintNameIsolationPortabilityMigration::needsIsolation('fk_org_site'));
        self::assertTrue(ConstraintNameIsolationPortabilityMigration::needsIsolation(
            'fk_fk_site_group_member_group',
        ));
        self::assertFalse(ConstraintNameIsolationPortabilityMigration::needsIsolation($isolated));
        self::assertFalse(ConstraintNameIsolationPortabilityMigration::needsIsolation(''));
    }

    /**
     * The MySQL family retains create-before-drop and resumes with only the verified drop.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMySqlStatementsRemainLosslessAndReplayable(): void
    {
        $database = $this->offlineConnection('pdo_mysql', '10.11.14-MariaDB');
        $target = ConstraintNameIsolationPortabilityMigration::isolatedName(
            'kumwe_organizations',
            'fk_org_site',
        );

        $fresh = $this->renameStatements($database, $target, false);
        self::assertCount(2, $fresh);
        self::assertStringContainsString('ADD CONSTRAINT ' . $target, $fresh[0]);
        self::assertStringContainsString('ON DELETE CASCADE', $fresh[0]);
        self::assertSame('ALTER TABLE `kumwe_organizations` DROP FOREIGN KEY `fk_org_site`', $fresh[1]);
        self::assertSame(
            ['ALTER TABLE `kumwe_organizations` DROP FOREIGN KEY `fk_org_site`'],
            $this->renameStatements($database, $target, true),
        );
    }

    /**
     * PostgreSQL renames normally and drops only the old twin on a verified replay.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPostgreSqlReplayDoesNotRenameOntoAnExistingTarget(): void
    {
        $database = $this->offlineConnection('pdo_pgsql', '17.0');
        $target = ConstraintNameIsolationPortabilityMigration::isolatedName(
            'kumwe_organizations',
            'fk_org_site',
        );

        self::assertSame(
            [sprintf(
                'ALTER TABLE "kumwe_organizations" RENAME CONSTRAINT "fk_org_site" TO "%s"',
                $target,
            )],
            $this->renameStatements($database, $target, false),
        );
        $replay = $this->renameStatements($database, $target, true);
        self::assertCount(1, $replay);
        self::assertStringContainsString('DROP CONSTRAINT "fk_org_site"', $replay[0]);
    }

    /**
     * Replay equality covers the relationship and its referential actions, not the target name alone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReplayTargetShapeIncludesColumnsTableAndActions(): void
    {
        $migration = $this->migration();
        $sameShape = new ReflectionMethod($migration, 'sameShape');
        $source = $this->foreignKey('fk_org_site', 'site', ReferentialAction::CASCADE);
        $matching = $this->foreignKey('isolated', 'site', ReferentialAction::CASCADE);
        $wrongColumn = $this->foreignKey('isolated', 'other_site', ReferentialAction::CASCADE);
        $wrongAction = $this->foreignKey('isolated', 'site', ReferentialAction::RESTRICT);

        self::assertSame(true, $sameShape->invoke($migration, $source, $matching));
        self::assertSame(false, $sameShape->invoke($migration, $source, $wrongColumn));
        self::assertSame(false, $sameShape->invoke($migration, $source, $wrongAction));
    }

    /**
     * Migration ledgers partition a shorter prefix from its initialized longer-prefix neighbour.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOverlappingPrefixesArePartitionedByTheirLedgers(): void
    {
        $migration = $this->migration();
        /** @var list<string> $prefixes */
        $prefixes = (new ReflectionMethod($migration, 'installationPrefixes'))->invoke($migration, [
            'app_schema_migrations',
            'app_sites',
            'app_two_schema_migrations',
            'app_two_sites',
        ]);
        $belongs = new ReflectionMethod($migration, 'belongsToInstallation');

        self::assertSame(['app_', 'app_two_'], $prefixes);
        self::assertSame(true, $belongs->invoke($migration, 'app_sites', 'app_', $prefixes));
        self::assertSame(false, $belongs->invoke($migration, 'app_two_sites', 'app_', $prefixes));
        self::assertSame(true, $belongs->invoke($migration, 'app_two_sites', 'app_two_', $prefixes));
    }

    /**
     * Compose one rename through the private platform branch under test.
     *
     * @param   Connection  $database  Offline connection selecting the SQL platform.
     * @param   string      $target    Isolated target name.
     * @param   bool        $created   Whether that target already exists.
     *
     * @return  list<string>  Statements in execution order.
     *
     * @since   2.0.0
     */
    private function renameStatements(Connection $database, string $target, bool $created): array
    {
        $migration = new ConstraintNameIsolationPortabilityMigration(new TableNames($database, 'kumwe_'));
        /** @var list<string> $statements */
        $statements = (new ReflectionMethod($migration, 'renameStatements'))->invoke(
            $migration,
            $database,
            'kumwe_organizations',
            'fk_org_site',
            $target,
            $this->foreignKey('fk_org_site', 'site', ReferentialAction::CASCADE),
            $created,
        );

        return $statements;
    }

    /**
     * Build one named foreign-key shape for statement and replay comparisons.
     *
     * @param   string             $name      Constraint name, irrelevant to structural equality.
     * @param   string             $column    Referencing column name.
     * @param   ReferentialAction  $onDelete  Delete action the rebuilt constraint must preserve.
     *
     * @return  ForeignKeyConstraint  Foreign key against the test sites table.
     *
     * @since   2.0.0
     */
    private function foreignKey(
        string $name,
        string $column,
        ReferentialAction $onDelete,
    ): ForeignKeyConstraint {
        return ForeignKeyConstraint::editor()
            ->setUnquotedName($name)
            ->setUnquotedReferencingColumnNames($column)
            ->setUnquotedReferencedTableName('kumwe_sites')
            ->setUnquotedReferencedColumnNames('identifier')
            ->setOnDeleteAction($onDelete)
            ->create();
    }

    /**
     * Open an offline connection for one supported platform family.
     *
     * @param   string  $driver         Doctrine driver name.
     * @param   string  $serverVersion  Version used for platform selection.
     *
     * @return  Connection  Connection that has not opened a database socket.
     *
     * @since   2.0.0
     */
    private function offlineConnection(string $driver, string $serverVersion): Connection
    {
        return DriverManager::getConnection(['driver' => $driver, 'serverVersion' => $serverVersion]);
    }

    /**
     * Build the follow-up over a prefix-aware table map.
     *
     * @return  ConstraintNameIsolationPortabilityMigration  Migration under test.
     *
     * @since   2.0.0
     */
    private function migration(): ConstraintNameIsolationPortabilityMigration
    {
        return new ConstraintNameIsolationPortabilityMigration(
            new TableNames($this->createStub(Connection::class), 'kumwe_'),
        );
    }
}
