<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\JsonType;
use Kumwe\App\Infrastructure\Persistence\Migration\DemoProfileProvenanceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\RepeatableMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the portable schema contract used by the demo profile reconciler.
 *
 * @since  2.0.0
 */
#[CoversClass(DemoProfileProvenanceMigration::class)]
final class DemoProfileProvenanceMigrationTest extends TestCase
{
    /**
     * Proves selector state has one row per site dataset and profile changes cannot create parallel ledgers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInstallationLedgerSeparatesDatasetIdentityFromSelectedProfile(): void
    {
        [$installations] = $this->definitions();

        foreach (
            [
                'site_identifier',
                'dataset_key',
                'selected_profile',
                'manifest_version',
                'manifest_checksum',
                'status',
                'created_at',
                'updated_at',
                'last_applied_at',
            ] as $column
        ) {
            self::assertTrue($installations->hasColumn($column), sprintf('Missing installation column %s.', $column));
        }
        self::assertSame(
            ['site_identifier', 'dataset_key'],
            $this->primaryColumns($installations),
        );
        self::assertInstanceOf(IntegerType::class, $installations->getColumn('manifest_version')->getType());
        self::assertSame(64, $installations->getColumn('manifest_checksum')->getLength());
        self::assertTrue($installations->getColumn('manifest_checksum')->getFixed());
        self::assertSame(24, $installations->getColumn('status')->getLength());
        self::assertTrue($installations->hasIndex('idx_demo_profile_installation_status'));
    }

    /**
     * Proves every fixture record carries replay evidence and belongs to exactly one site dataset ledger.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAssetLedgerCarriesOwnershipStateAndInstallationForeignKey(): void
    {
        [, $assets] = $this->definitions();

        foreach (
            [
                'site_identifier',
                'dataset_key',
                'fixture_key',
                'resource_id',
                'resource_type',
                'last_applied_checksum',
                'last_applied_version',
                'last_applied_state',
                'first_applied_at',
                'last_applied_at',
                'updated_at',
            ] as $column
        ) {
            self::assertTrue($assets->hasColumn($column), sprintf('Missing asset column %s.', $column));
        }
        self::assertSame(
            ['site_identifier', 'dataset_key', 'fixture_key'],
            $this->primaryColumns($assets),
        );
        self::assertInstanceOf(BigIntType::class, $assets->getColumn('last_applied_version')->getType());
        self::assertInstanceOf(JsonType::class, $assets->getColumn('last_applied_state')->getType());
        self::assertSame(64, $assets->getColumn('last_applied_checksum')->getLength());
        self::assertTrue($assets->getColumn('last_applied_checksum')->getFixed());
        self::assertTrue($assets->hasIndex('idx_demo_profile_asset_resource'));
        $foreignKeys = array_values($assets->getForeignKeys());
        self::assertCount(1, $foreignKeys);
        $foreignKey = $foreignKeys[0] ?? null;
        self::assertInstanceOf(ForeignKeyConstraint::class, $foreignKey);
        self::assertSame('kumwe_demo_profile_installations', $foreignKey->getReferencedTableName()->toString());
        self::assertSame(
            ['site_identifier', 'dataset_key'],
            array_map(static fn (Name $name): string => $name->toString(), $foreignKey->getReferencingColumnNames()),
        );
        self::assertSame(
            ['site_identifier', 'dataset_key'],
            array_map(static fn (Name $name): string => $name->toString(), $foreignKey->getReferencedColumnNames()),
        );
    }

    /**
     * Proves an interrupted implicit-commit run may replay and the source-bound checksum has ledger form.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMigrationDeclaresRepeatabilityAndAValidChecksum(): void
    {
        $database = $this->createStub(Connection::class);
        $migration = new DemoProfileProvenanceMigration(new TableNames($database, 'kumwe_'));

        self::assertInstanceOf(RepeatableMigration::class, $migration);
        self::assertSame(DemoProfileProvenanceMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
    }

    /**
     * Build the two portable table declarations without opening a database connection.
     *
     * @return  array{Table, Table}  Installation and asset tables in dependency order.
     *
     * @since   2.0.0
     */
    private function definitions(): array
    {
        $database = $this->createStub(Connection::class);
        $migration = new DemoProfileProvenanceMigration(new TableNames($database, 'kumwe_'));
        /** @var list<Table> $definitions */
        $definitions = (new ReflectionMethod($migration, 'tables'))->invoke($migration);
        self::assertCount(2, $definitions);
        $installations = $definitions[0] ?? null;
        $assets = $definitions[1] ?? null;
        self::assertInstanceOf(Table::class, $installations);
        self::assertInstanceOf(Table::class, $assets);

        return [$installations, $assets];
    }

    /**
     * Read a table's composite primary-key columns in declaration order.
     *
     * @param   Table  $table  Portable table declaration carrying the primary key.
     *
     * @return  list<string>  Unquoted column names in constraint order.
     *
     * @since   2.0.0
     */
    private function primaryColumns(Table $table): array
    {
        $primary = $table->getPrimaryKeyConstraint();
        self::assertInstanceOf(PrimaryKeyConstraint::class, $primary);

        return array_map(static fn (Name $name): string => $name->toString(), $primary->getColumnNames());
    }
}
