<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentAuthoringStartMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Drives the Studio authoring start migration against private tables on the configured engine.
 *
 * The shared installation only proves the column exists once. The transformation's own properties — that it
 * adds the optional start column to a context table that predates it, that a replay is a no-op, and that it
 * refuses an installation whose context table is missing instead of inventing one — are proven by applying
 * it to tables under a prefix unique to the test, so no run can disturb the installation the suite shares.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioContentAuthoringStartMigration::class)]
final class StudioContentAuthoringStartMigrationIntegrationTest extends TestCase
{
    /**
     * A context table without the start column gains an optional one, and a replay changes nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAContextTableGainsAnOptionalStartColumnOnceAndAReplayChangesNothing(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);
        $name = $tables->raw('studio_content_authoring_contexts');
        $manager = $database->createSchemaManager();
        $contexts = new Table($name);
        $contexts->addColumn('id', Types::GUID);
        $contexts->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $contexts->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );
        $manager->createTable($contexts);
        $id = Uuid::uuid7()->toString();
        $database->insert($name, ['id' => $id, 'site_identifier' => 'default']);

        try {
            $migration = new StudioContentAuthoringStartMigration($tables);
            $migration->up($database);
            $built = $manager->introspectTableByUnquotedName($name);
            self::assertTrue($built->hasColumn('start_source'));
            self::assertFalse($built->getColumn('start_source')->getNotnull());
            self::assertSame(1000, $built->getColumn('start_source')->getLength());
            self::assertNull(
                $database->fetchOne(sprintf('SELECT start_source FROM %s WHERE id = ?', $tables->quoted(
                    'studio_content_authoring_contexts',
                )), [$id]),
                'A context that predates the column has no recorded start.',
            );

            $migration->up($database);
            $replayed = $manager->introspectTableByUnquotedName($name);
            self::assertSame(
                array_keys($built->getColumns()),
                array_keys($replayed->getColumns()),
                'A replay neither adds nor removes a column.',
            );
        } finally {
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tables->quoted(
                'studio_content_authoring_contexts',
            )));
        }
    }

    /**
     * An installation without the context table is refused rather than given a table the migration invents.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInstallationWithoutTheContextTableIsRefused(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);

        try {
            (new StudioContentAuthoringStartMigration($tables))->up($database);
            self::fail('The start migration must not run before the context table exists.');
        } catch (RuntimeException $refusal) {
            self::assertSame(
                'The Studio Content authoring context table must exist before its start.',
                $refusal->getMessage(),
            );
        }
        self::assertFalse($database->createSchemaManager()->tablesExist([
            $tables->raw('studio_content_authoring_contexts'),
        ]));
    }

    /**
     * The migration names itself and checksums its own bytes, so an edit after release is detectable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMigrationIdentityAndChecksumAreDerivedFromItsOwnBytes(): void
    {
        $migration = new StudioContentAuthoringStartMigration($this->tables($this->connection()));
        $file = (new \ReflectionClass(StudioContentAuthoringStartMigration::class))->getFileName();
        self::assertIsString($file);

        self::assertSame('20260924060000_studio_content_authoring_start', $migration->id());
        self::assertSame(
            hash('sha256', StudioContentAuthoringStartMigration::ID . ':' . hash_file('sha256', $file)),
            $migration->checksum(),
        );
    }

    /**
     * Open the integration connection.
     *
     * @return  Connection  Connection to the configured engine.
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
     * @return  TableNames  Compiler bound to a prefix unique to this test method.
     *
     * @since   2.0.0
     */
    private function tables(Connection $database): TableNames
    {
        return new TableNames($database, 's' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10) . '_');
    }
}
