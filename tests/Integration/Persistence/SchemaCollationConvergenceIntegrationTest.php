<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\App\Infrastructure\Persistence\SchemaCollationConvergence;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves a migration pass leaves every application table on one collation, whatever the server defaulted to.
 *
 * DBAL creates some tables with a bare `DEFAULT CHARACTER SET utf8mb4`, which MariaDB and MySQL resolve
 * to the character set's default collation rather than the database's, so a database created with an
 * explicit `COLLATE`, or a server with a `collation-server` of its own, ends up with two collations and
 * every join across the split failing with "Illegal mix of collations". The proof plants exactly that
 * split — two prefixed tables on a collation the database does not default to, joined by a foreign key
 * that would block the conversion — and shows the convergence rewrites both onto the database default,
 * keeps the foreign key with its name and rules, and finds nothing to do on the second pass. PostgreSQL
 * carries one collation per database, so there the convergence proves it is a no-op.
 *
 * @since  2.0.0
 */
#[CoversClass(SchemaCollationConvergence::class)]
final class SchemaCollationConvergenceIntegrationTest extends TestCase
{
    /**
     * Two straying tables converge on the database default with their foreign key intact, then nothing moves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStrayingTablesConvergeOnTheDatabaseDefaultAndKeepTheirForeignKey(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $convergence = new SchemaCollationConvergence($database, $tables);

        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertNull($convergence->target(), 'PostgreSQL has one collation per database.');
            self::assertSame([], $convergence->converge());

            return;
        }

        $target = $convergence->target();
        self::assertIsString($target);
        self::assertStringStartsWith('utf8mb4', $target);
        $stray = $target === 'utf8mb4_bin' ? 'utf8mb4_general_ci' : 'utf8mb4_bin';
        $parent = $tables->raw('zz_collation_parent');
        $child = $tables->raw('zz_collation_child');
        $constraint = 'fk_zz_collation_child_parent';
        $this->dropFixture($database, $parent, $child);

        try {
            $database->executeStatement(sprintf(
                'CREATE TABLE %s (id CHAR(36) NOT NULL, PRIMARY KEY (id))'
                . ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE %s',
                $database->quoteSingleIdentifier($parent),
                $stray,
            ));
            $database->executeStatement(sprintf(
                'CREATE TABLE %s (id CHAR(36) NOT NULL, parent_id CHAR(36) NOT NULL, label VARCHAR(40) NOT NULL,'
                . ' PRIMARY KEY (id), KEY idx_zz_collation_child_parent (parent_id),'
                . ' CONSTRAINT %s FOREIGN KEY (parent_id) REFERENCES %s (id) ON DELETE CASCADE ON UPDATE RESTRICT)'
                . ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE %s',
                $database->quoteSingleIdentifier($child),
                $database->quoteSingleIdentifier($constraint),
                $database->quoteSingleIdentifier($parent),
                $stray,
            ));
            $database->executeStatement(sprintf(
                'INSERT INTO %s (id) VALUES (?)',
                $database->quoteSingleIdentifier($parent),
            ), ['0192a000-0000-7000-8000-000000000001']);
            $database->executeStatement(sprintf(
                'INSERT INTO %s (id, parent_id, label) VALUES (?, ?, ?)',
                $database->quoteSingleIdentifier($child),
            ), ['0192a000-0000-7000-8000-000000000002', '0192a000-0000-7000-8000-000000000001', 'kept']);
            self::assertSame($stray, $this->tableCollation($database, $child));

            $converged = $convergence->converge();

            self::assertContains($parent, $converged);
            self::assertContains($child, $converged);
            self::assertSame($converged, array_values(array_unique($converged)));
            foreach ([$parent, $child] as $table) {
                self::assertSame($target, $this->tableCollation($database, $table), $table);
                self::assertSame([], $this->columnsOffTarget($database, $table, $target), $table);
            }
            self::assertSame(
                ['CASCADE', 'RESTRICT', $parent],
                $this->foreignKey($database, $constraint),
                'The foreign key must survive the conversion with its name, target and rules.',
            );
            self::assertSame('kept', $database->fetchOne(sprintf(
                'SELECT c.label FROM %s c INNER JOIN %s p ON p.id = c.parent_id',
                $database->quoteSingleIdentifier($child),
                $database->quoteSingleIdentifier($parent),
            )), 'Rows must survive, and the join that motivates the convergence must now be legal.');
            self::assertSame([], $convergence->converge(), 'A consistent schema is left untouched.');
        } finally {
            $this->dropFixture($database, $parent, $child);
        }
    }

    /**
     * Drop the two fixture tables, child first, ignoring their absence.
     *
     * @param   Connection  $database  Connection to the integration database.
     * @param   string      $parent    Physical name of the referenced table.
     * @param   string      $child     Physical name of the referencing table.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function dropFixture(Connection $database, string $parent, string $child): void
    {
        foreach ([$child, $parent] as $table) {
            $database->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s',
                $database->quoteSingleIdentifier($table),
            ));
        }
    }

    /**
     * Read one table's own collation from the catalogue.
     *
     * @param   Connection  $database  Connection to the integration database.
     * @param   string      $table     Physical table name.
     *
     * @return  string  The table collation as the engine reports it.
     *
     * @since   2.0.0
     */
    private function tableCollation(Connection $database, string $table): string
    {
        $collation = $database->fetchOne(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );
        self::assertIsString($collation);

        return $collation;
    }

    /**
     * List the `utf8mb4` columns of one table whose collation is not the target.
     *
     * @param   Connection  $database  Connection to the integration database.
     * @param   string      $table     Physical table name.
     * @param   string      $target    Expected collation.
     *
     * @return  list<mixed>  Offending column names; empty when every textual column agrees.
     *
     * @since   2.0.0
     */
    private function columnsOffTarget(Connection $database, string $table, string $target): array
    {
        return $database->fetchFirstColumn(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CHARACTER_SET_NAME = \'utf8mb4\''
            . ' AND COLLATION_NAME <> ?',
            [$table, $target],
        );
    }

    /**
     * Read one foreign key's rules and referenced table from the catalogue.
     *
     * @param   Connection  $database    Connection to the integration database.
     * @param   string      $constraint  Constraint name.
     *
     * @return  list<mixed>  Delete rule, update rule and referenced table; empty when the key is missing.
     *
     * @since   2.0.0
     */
    private function foreignKey(Connection $database, string $constraint): array
    {
        $row = $database->fetchAssociative(
            'SELECT DELETE_RULE, UPDATE_RULE, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            [$constraint],
        );

        return $row === false ? [] : array_values($row);
    }
}
