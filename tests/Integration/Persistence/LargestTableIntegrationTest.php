<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\DoctrineLargestTable;
use Kumwe\App\Infrastructure\Persistence\FilesystemStorageReserve;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the largest-table probe reads the engine's catalogue for exactly this installation's prefix.
 *
 * A sibling installation holds one small and one large table; the probe reports the large one, an
 * installation with no tables reports zero, and the storage guardrail carries the size into its report.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineLargestTable::class)]
#[CoversClass(FilesystemStorageReserve::class)]
final class LargestTableIntegrationTest extends TestCase
{
    /**
     * The probe reports the sibling's largest table and nothing for an empty prefix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheLargestPrefixedTableIsReported(): void
    {
        $connection = (new DoctrineConnectionFactory(
            (new ConfigurationFactory())->create(Environment::fromGlobals())->database,
        ))->create();
        $prefix = 'kl' . bin2hex(random_bytes(3)) . '_';
        $tables = new TableNames($connection, $prefix);
        try {
            $schema = new Schema();
            foreach (['tiny', 'ballast'] as $name) {
                $table = $schema->createTable($tables->raw($name));
                $table->addColumn('id', Types::INTEGER);
                $table->addColumn('filler', Types::STRING, ['length' => 255]);
                $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
            }
            foreach ($schema->toSql($connection->getDatabasePlatform()) as $statement) {
                $connection->executeStatement($statement);
            }
            $connection->insert($tables->raw('tiny'), ['id' => 1, 'filler' => 'x']);
            $connection->beginTransaction();
            for ($row = 1; $row <= 3_000; $row++) {
                $connection->insert($tables->raw('ballast'), ['id' => $row, 'filler' => str_repeat('b', 250)]);
            }
            $connection->commit();
            $this->analyse($connection, $tables);
            $probe = new DoctrineLargestTable($connection, $tables);
            $bytes = $probe->bytes();
            self::assertGreaterThanOrEqual(500_000, $bytes, 'Three thousand 250-byte rows occupy at least 500 kB.');
            $report = (new FilesystemStorageReserve(sys_get_temp_dir(), 0.0001, $probe))->report();
            self::assertNotNull($report);
            self::assertSame($bytes, $report['largest_table_bytes']);
            self::assertSame(2 * $bytes, $report['rebuild_bytes_required']);
            $empty = new TableNames($connection, 'ke' . bin2hex(random_bytes(3)) . '_');
            self::assertSame(0, (new DoctrineLargestTable($connection, $empty))->bytes());
        } finally {
            $manager = $connection->createSchemaManager();
            foreach (['tiny', 'ballast'] as $name) {
                if ($manager->tablesExist([$tables->raw($name)])) {
                    $manager->dropTable($tables->quoted($name));
                }
            }
            $connection->close();
        }
    }

    /**
     * Refresh the catalogue's size statistics for the sibling tables.
     *
     * @param   Connection  $connection  Connection.
     * @param   TableNames  $tables      Sibling names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function analyse(Connection $connection, TableNames $tables): void
    {
        foreach (['tiny', 'ballast'] as $name) {
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $connection->executeStatement('ANALYZE ' . $tables->quoted($name));
            } else {
                $connection->fetchAllAssociative('ANALYZE TABLE ' . $tables->quoted($name));
            }
        }
    }
}
