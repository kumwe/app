<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\Infrastructure\Persistence\Migration\IndexNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationPlan;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use ReflectionProperty;

/**
 * Proves two complete prefixed core migration plans coexist in one PostgreSQL schema.
 *
 * This is the schema-wide acceptance proof for `V2-DB-004`: PostgreSQL keeps non-primary index names
 * in a schema-global namespace, so before the index-name isolation a second prefixed plan failed at
 * `uniq_org_site_identifier` — the focused fixture in
 * `ConstraintNameIsolationPortabilityIntegrationTest` had to assign test-only index names just to
 * reach its foreign-key subject. Here nothing is substituted: two complete empty-prefix core plans
 * run sequentially through the real kernel into the configured schema, both finish, a catalogue read
 * proves every non-primary index name is unique across both installations and isolated, both hold
 * identical logical index structure, and replaying the plan changes nothing.
 *
 * @since  2.0.0
 */
#[CoversClass(IndexNameIsolationMigration::class)]
final class IndexNameIsolationCoexistenceIntegrationTest extends TestCase
{
    /**
     * Two complete prefixed core plans run sequentially in one schema, and both finish isolated.
     *
     * The catalogue assertions are the acceptance contract: every non-primary index on either
     * installation's tables carries a digest-derived name within the portable identifier budget, no
     * name appears twice across the two installations, the two installations hold the same logical
     * indexes — same logical table, uniqueness, key columns, predicate and method — under different
     * physical names, and each ledger covers the whole plan. Replaying the plan on the first
     * installation is then proven to change nothing, which is the re-entrancy half of the contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoCompletePrefixedCorePlansCoexistInOneSchema(): void
    {
        $database = $this->database();
        $first = $this->prefix();
        $second = $this->prefix();

        try {
            $container = TestKernelFactory::create($this->environmentWithPrefix($first));
            TestKernelFactory::create($this->environmentWithPrefix($second));

            $catalogue = $this->catalogue($database);
            $firstIndexes = $this->installationIndexes($catalogue, $first);
            $secondIndexes = $this->installationIndexes($catalogue, $second);
            self::assertNotSame([], $firstIndexes);
            self::assertNotSame([], $secondIndexes);

            $names = [...array_keys($firstIndexes), ...array_keys($secondIndexes)];
            self::assertSame(count($names), count(array_unique($names)));
            foreach ($names as $name) {
                self::assertFalse(IndexNameIsolationMigration::needsIsolation($name), $name);
                self::assertLessThanOrEqual(
                    IndexNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES,
                    strlen($name),
                    $name,
                );
            }

            $firstShapes = $this->logicalShapes($firstIndexes, $first);
            $secondShapes = $this->logicalShapes($secondIndexes, $second);
            self::assertSame($firstShapes, $secondShapes);

            $plan = $container->get(MigrationPlan::class);
            self::assertInstanceOf(MigrationPlan::class, $plan);
            foreach ([$first, $second] as $prefix) {
                self::assertSame(
                    (string) count($plan->ids()),
                    (string) $database->fetchOne(sprintf(
                        'SELECT COUNT(*) FROM %s',
                        $database->quoteSingleIdentifier($prefix . 'schema_migrations'),
                    )),
                    $prefix,
                );
            }

            TestKernelFactory::create($this->environmentWithPrefix($first));
            self::assertSame(
                $firstIndexes,
                $this->installationIndexes($this->catalogue($database), $first),
                'Replaying the complete plan must not rename, add or drop a single index.',
            );
        } finally {
            $this->dropInstallation($database, $first);
            $this->dropInstallation($database, $second);
        }
    }

    /**
     * Read every index in the current schema, with its table and its full catalogue definition.
     *
     * @param   Connection  $database  Connection to the schema under proof.
     *
     * @return  list<array{index: string, table: string, definition: string}>  Non-primary index rows.
     *
     * @since   2.0.0
     */
    private function catalogue(Connection $database): array
    {
        /** @var list<array{index: string, table: string, definition: string}> $rows */
        $rows = $database->fetchAllAssociative(
            'SELECT c.relname AS "index", t.relname AS "table", pg_get_indexdef(i.indexrelid) AS definition
                FROM pg_index i
                INNER JOIN pg_class c ON c.oid = i.indexrelid
                INNER JOIN pg_class t ON t.oid = i.indrelid
                INNER JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = current_schema() AND NOT i.indisprimary
                ORDER BY c.relname',
        );
        self::assertNotSame([], $rows);

        return $rows;
    }

    /**
     * Select the catalogue rows belonging to one installation, keyed by index name.
     *
     * @param   list<array{index: string, table: string, definition: string}>  $catalogue  Schema rows.
     * @param   string                                                         $prefix     Table prefix.
     *
     * @return  array<string, array{table: string, definition: string}>  That installation's indexes.
     *
     * @since   2.0.0
     */
    private function installationIndexes(array $catalogue, string $prefix): array
    {
        $indexes = [];
        foreach ($catalogue as $row) {
            if (str_starts_with($row['table'], $prefix)) {
                $indexes[$row['index']] = ['table' => $row['table'], 'definition' => $row['definition']];
            }
        }

        return $indexes;
    }

    /**
     * Normalize one installation's indexes to their prefix- and name-independent logical shape.
     *
     * The physical index name and the physical table name are the two parts the isolation is allowed
     * to vary between installations; everything else in the catalogue definition — uniqueness, the
     * access method, the ordered key columns, any predicate, expression or included column — must be
     * identical, so it is kept verbatim and compared across the two installations.
     *
     * @param   array<string, array{table: string, definition: string}>  $indexes  One installation's rows.
     * @param   string                                                   $prefix   Its table prefix.
     *
     * @return  list<string>  Sorted logical index shapes.
     *
     * @since   2.0.0
     */
    private function logicalShapes(array $indexes, string $prefix): array
    {
        $shapes = [];
        foreach ($indexes as $name => $row) {
            $logicalTable = substr($row['table'], strlen($prefix));
            $shapes[] = str_replace(
                [' INDEX ' . $name . ' ON ', '.' . $row['table'] . ' '],
                [' INDEX * ON ', '.' . $logicalTable . ' '],
                $row['definition'],
            );
        }
        sort($shapes, SORT_STRING);

        return $shapes;
    }

    /**
     * Copy the configured environment, swapping in the prefix one installation is built under.
     *
     * @param   string  $prefix  Table prefix the copied environment configures.
     *
     * @return  Environment  Same connection, credentials and runtime settings, different prefix.
     *
     * @since   2.0.0
     */
    private function environmentWithPrefix(string $prefix): Environment
    {
        /** @var array<string, string> $values */
        $values = (new ReflectionProperty(Environment::class, 'values'))
            ->getValue(Environment::fromGlobals());
        $values['DB_TABLE_PREFIX'] = $prefix;

        return new Environment($values);
    }

    /**
     * Remove every table and routine one installation created, so a rerun starts from nothing.
     *
     * Tables are dropped with `CASCADE` because the installation's foreign keys interlink them, and
     * the audit trail's prefix-named append-only routine is removed alongside — it is the one
     * schema-level object the plan installs outside a table.
     *
     * @param   Connection  $database  Connection to the schema under proof.
     * @param   string      $prefix    Table prefix whose installation is removed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function dropInstallation(Connection $database, string $prefix): void
    {
        /** @var list<string> $tables */
        $tables = $database->fetchFirstColumn(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE '
                . $database->quote(str_replace('_', '\_', $prefix) . '%'),
        );
        foreach ($tables as $table) {
            $database->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s CASCADE',
                $database->quoteSingleIdentifier($table),
            ));
        }
        $database->executeStatement(sprintf(
            'DROP FUNCTION IF EXISTS %s() CASCADE',
            $database->quoteSingleIdentifier($prefix . 'audit_append_only'),
        ));
    }

    /**
     * Mint a prefix no other installation, run or case in this suite is using.
     *
     * The randomness has to come from the tail of the identifier: a version 7 UUID opens with a
     * millisecond timestamp, so two minted in the same millisecond — which is what this case does —
     * would share their leading characters and collide with each other.
     *
     * @return  non-empty-string  Valid table prefix unique to this installation.
     *
     * @since   2.0.0
     */
    private function prefix(): string
    {
        return 'g' . substr(str_replace('-', '', Uuid::uuid7()->toString()), -10) . '_';
    }

    /**
     * Open the configured connection, skipping where the namespace this proves is not schema-global.
     *
     * The MySQL family scopes an index name to its table, so the schema-wide coexistence proof is
     * PostgreSQL's; the rename itself is proven on every engine by
     * `IndexNameIsolationIntegrationTest` and by the ordinary migration run.
     *
     * @return  Connection  Connection to the configured test database.
     *
     * @since   2.0.0
     */
    private function database(): Connection
    {
        $database = TestKernelFactory::create(Environment::fromGlobals())->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        if (!$database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('An index name is schema-global only on PostgreSQL.');
        }

        return $database;
    }
}
