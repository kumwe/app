<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessNumberSequenceMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\NumberSequenceIdentityMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Drives the counter identity certification itself, against counters seeded the way the allocator writes.
 *
 * The `V2-ERP-002` widening proof is the property this suite pins: every counter that exists before the
 * widening maps forward to exactly one widened counter — the five-coordinate tuple of site, definition,
 * field handle, scope key and period key — with its committed value intact. The migration certifies that
 * mapping on the operator's own data at upgrade time, so it is proven here by applying it in-process to a
 * private copy of `business_number_sequences` under a per-test prefix, where a run cannot disturb the
 * installation the rest of the suite shares. The refusal paths matter as much as the pass: a table whose
 * identity is incomplete, or a row no widened coordinate tuple could name, must fail the upgrade loudly
 * rather than allocate ambiguously later.
 *
 * @since  2.0.0
 */
#[CoversClass(NumberSequenceIdentityMigration::class)]
final class NumberSequenceIdentityMigrationIntegrationTest extends TestCase
{
    /**
     * Every seeded counter survives the certification unchanged, once per identity tuple, and a replay
     * changes nothing.
     *
     * The seeds cover the shapes the shipped vocabulary produces: a site-wide yearly run, a
     * per-organization monthly run on the same document type, and a lifetime run for a second legal
     * entity's own document type. The forward mapping is the identity mapping, so the proof is that the
     * row count, the coordinates and every `current_value` are bit-for-bit what was seeded — after one
     * application and after two.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryExistingCounterMapsForwardToExactlyOneWidenedCounterWithItsValueIntact(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);
        $definitionA = Uuid::uuid7()->toString();
        $definitionB = Uuid::uuid7()->toString();
        $seeds = [
            ['default', $definitionA, 'document_number', '-', '2026', 41],
            ['default', $definitionA, 'document_number', 'north-branch', '2026-08', 7],
            ['entity-b', $definitionB, 'voucher_number', '-', '', 123],
        ];

        try {
            (new BusinessNumberSequenceMigration($tables))->up($database);
            foreach ($seeds as $seed) {
                $this->seed($database, $tables, $seed);
            }

            $migration = new NumberSequenceIdentityMigration($tables);
            $migration->up($database);
            $migration->up($database);

            self::assertSame('20260822010000_number_sequence_identity', $migration->id());
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $migration->checksum());
            self::assertSame($migration->checksum(), $migration->checksum());
            self::assertSame(count($seeds), $this->rowCount($database, $tables));
            foreach ($seeds as [$site, $definition, $field, $scope, $period, $value]) {
                $stored = $database->fetchFirstColumn(sprintf(
                    'SELECT current_value FROM %s WHERE site_identifier = ? AND definition_id = ? '
                    . 'AND field_handle = ? AND scope_key = ? AND period_key = ?',
                    $tables->quoted('business_number_sequences'),
                ), [$site, $definition, $field, $scope, $period]);
                self::assertCount(1, $stored, 'Each counter must map forward to exactly one widened counter.');
                self::assertSame($value, (int) $stored[0], 'A mapped-forward counter keeps its committed value.');
            }
        } finally {
            $this->dropTable($database, $tables);
        }
    }

    /**
     * A row no widened coordinate tuple could name fails the upgrade loudly and is never repaired silently.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACounterRowMissingAnIdentityCoordinateFailsTheUpgradeLoudly(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);

        try {
            (new BusinessNumberSequenceMigration($tables))->up($database);
            $this->seed($database, $tables, ['default', Uuid::uuid7()->toString(), '', '-', '2026', 5]);

            try {
                (new NumberSequenceIdentityMigration($tables))->up($database);
                self::fail('A counter with an empty identity coordinate must refuse the certification.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('cannot serve as widened counters', $exception->getMessage());
            }
            self::assertSame(
                1,
                $this->rowCount($database, $tables),
                'A refused certification leaves the malformed row for the operator rather than repairing it.',
            );
        } finally {
            $this->dropTable($database, $tables);
        }
    }

    /**
     * The certification refuses to stand in for the counter table's own installation step.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCertificationRefusesToRunWithoutTheCounterTable(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/counter table is missing/');
        (new NumberSequenceIdentityMigration($tables))->up($database);
    }

    /**
     * An incomplete identity — a missing column or a missing arbitration index — is refused by name.
     *
     * The index half is the injectivity of the forward mapping: without the unique index over the
     * five-coordinate tuple, one widened counter could be named by two rows, which is exactly the
     * ambiguity the certification exists to rule out.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATableMissingAColumnOrItsIdentityIndexIsRefused(): void
    {
        $database = $this->connection();
        $withoutColumn = $this->tables($database);
        $withoutIndex = $this->tables($database);

        try {
            $manager = $database->createSchemaManager();
            $manager->createTable($this->counterTable($withoutColumn, false, false));
            try {
                (new NumberSequenceIdentityMigration($withoutColumn))->up($database);
                self::fail('A counter table without its period_key column must be refused.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('missing its period_key column', $exception->getMessage());
            }

            $manager->createTable($this->counterTable($withoutIndex, true, false));
            try {
                (new NumberSequenceIdentityMigration($withoutIndex))->up($database);
                self::fail('A counter table without its identity index must be refused.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('no counter identity index', $exception->getMessage());
            }
        } finally {
            $this->dropTable($database, $withoutColumn);
            $this->dropTable($database, $withoutIndex);
        }
    }

    /**
     * Open the integration connection the configured engine provides.
     *
     * @return  Connection  Live integration connection.
     *
     * @since   2.0.0
     */
    private function connection(): Connection
    {
        $database = TestKernelFactory::create(Environment::fromGlobals())->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);

        return $database;
    }

    /**
     * Compile table names under a prefix no other test or installation can be using.
     *
     * @param   Connection  $database  Integration connection supplying identifier quoting.
     *
     * @return  TableNames  Compiler bound to a prefix unique to this call.
     *
     * @since   2.0.0
     */
    private function tables(Connection $database): TableNames
    {
        return new TableNames($database, 'n' . substr(str_replace('-', '', Uuid::uuid7()->toString()), -10) . '_');
    }

    /**
     * Insert one counter row exactly as the allocator's first-use seed writes it.
     *
     * @param   Connection                                       $database  Integration connection.
     * @param   TableNames                                       $tables    Unique test table-name compiler.
     * @param   array{string, string, string, string, string, int}  $seed   Site, definition, field handle,
     *          scope key, period key and committed value of the counter being seeded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function seed(Connection $database, TableNames $tables, array $seed): void
    {
        [$site, $definition, $field, $scope, $period, $value] = $seed;
        $now = new DateTimeImmutable('2026-08-18T09:00:00', new DateTimeZone('UTC'));
        $database->insert($tables->raw('business_number_sequences'), [
            'id' => Uuid::uuid7()->toString(),
            'site_identifier' => $site,
            'definition_id' => $definition,
            'field_handle' => $field,
            'scope_key' => $scope,
            'period_key' => $period,
            'current_value' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'id' => Types::GUID,
            'definition_id' => Types::GUID,
            'current_value' => Types::BIGINT,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Count the rows the private counter table holds.
     *
     * @param   Connection  $database  Integration connection.
     * @param   TableNames  $tables    Unique test table-name compiler.
     *
     * @return  int  Number of counter rows present.
     *
     * @since   2.0.0
     */
    private function rowCount(Connection $database, TableNames $tables): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $tables->quoted('business_number_sequences'),
        ));
    }

    /**
     * Declare a deliberately incomplete counter table for the refusal paths.
     *
     * @param   TableNames  $tables      Unique test table-name compiler.
     * @param   bool        $withPeriod  Whether the `period_key` column is declared.
     * @param   bool        $withIndex   Whether the identity unique index is declared.
     *
     * @return  Table  Portable table declaration missing the requested identity part.
     *
     * @since   2.0.0
     */
    private function counterTable(TableNames $tables, bool $withPeriod, bool $withIndex): Table
    {
        $table = new Table($tables->raw('business_number_sequences'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 64]);
        $table->addColumn('definition_id', Types::GUID);
        $table->addColumn('field_handle', Types::STRING, ['length' => 64]);
        $table->addColumn('scope_key', Types::STRING, ['length' => 191]);
        if ($withPeriod) {
            $table->addColumn('period_key', Types::STRING, ['length' => 16]);
        }
        $table->addColumn('current_value', Types::BIGINT, ['default' => 0]);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );
        if ($withIndex && $withPeriod) {
            $table->addUniqueIndex(
                ['site_identifier', 'definition_id', 'field_handle', 'scope_key', 'period_key'],
                $tables->raw('uniq_business_number_sequence'),
            );
        }

        return $table;
    }

    /**
     * Remove the private counter table a test created under its own prefix.
     *
     * @param   Connection  $database  Integration connection.
     * @param   TableNames  $tables    Unique test table-name compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function dropTable(Connection $database, TableNames $tables): void
    {
        $database->executeStatement(sprintf(
            'DROP TABLE IF EXISTS %s',
            $tables->quoted('business_number_sequences'),
        ));
    }
}
