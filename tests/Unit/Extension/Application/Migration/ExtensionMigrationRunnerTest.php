<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Application\Migration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Kumwe\App\Extension\Application\Migration\ExtensionMigrationRunner;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\Extension\Spi\Migration\ExtensionMigration;
use Kumwe\Extension\Spi\Migration\ExtensionTableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;

#[CoversClass(ExtensionMigrationRunner::class)]
/**
 * Proves declared migrations run exactly once, ledgered under confined names and canonical digests.
 *
 * @since  2.0.0
 */
final class ExtensionMigrationRunnerTest extends TestCase
{
    /**
     * Prove a never-applied migration runs with confined table names and writes its ledger row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeclaredMigrationAppliesOnceUnderConfinedNames(): void
    {
        ProbeItemsMigration::$rawNames = [];
        ProbeItemsMigration::$quotedNames = [];
        $platform = self::createStub(AbstractPlatform::class);
        $platform->method('quoteSingleIdentifier')
            ->willReturnCallback(static fn (string $part): string => '`' . $part . '`');
        $database = $this->createMock(Connection::class);
        $database->method('fetchOne')->willReturn(false);
        $database->method('quoteSingleIdentifier')
            ->willReturnCallback(static fn (string $part): string => '"' . $part . '"');
        $database->method('getDatabasePlatform')->willReturn($platform);
        $rows = [];
        $database->expects(self::once())->method('insert')
            ->willReturnCallback(static function (string $table, array $data) use (&$rows): int {
                $rows[] = [$table, $data];

                return 1;
            });
        $runner = new ExtensionMigrationRunner($database, new TableNames($database, 'kw_'), self::clock());

        $applied = $runner->apply(self::manifest(), self::deployment());

        self::assertCount(1, $applied);
        self::assertInstanceOf(ProbeItemsMigration::class, $applied[0]);
        self::assertSame(['kw_ext_acme_probe_items'], ProbeItemsMigration::$rawNames);
        self::assertSame(['`kw_ext_acme_probe_items`'], ProbeItemsMigration::$quotedNames);
        self::assertCount(1, $rows);
        [$table, $row] = $rows[0];
        self::assertSame('kw_extension_migrations', $table);
        self::assertSame('acme/probe', $row['extension_identifier']);
        self::assertSame('20260101120000_create_items', $row['migration_id']);
        self::assertSame(self::expectedChecksum(), $row['migration_sha256']);
        self::assertSame('1.0.0', $row['extension_version']);
    }

    /**
     * Prove a ledgered migration with a matching canonical digest is skipped without re-running.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARecordedMigrationWithAMatchingChecksumIsSkipped(): void
    {
        ProbeItemsMigration::$rawNames = [];
        ProbeItemsMigration::$quotedNames = [];
        $database = $this->createMock(Connection::class);
        $database->method('fetchOne')->willReturn(self::expectedChecksum());
        $database->method('quoteSingleIdentifier')
            ->willReturnCallback(static fn (string $part): string => '"' . $part . '"');
        $database->expects(self::never())->method('insert');
        $runner = new ExtensionMigrationRunner($database, new TableNames($database, 'kw_'), self::clock());

        self::assertSame([], $runner->apply(self::manifest(), self::deployment()));
        self::assertSame([], ProbeItemsMigration::$rawNames);
    }

    /**
     * Derive the canonical digest the ledger must carry for the probe migration.
     *
     * @return  string  Hex SHA-256 over class name, migration ID and source bytes.
     *
     * @since   2.0.0
     */
    private static function expectedChecksum(): string
    {
        $file = (string) (new ReflectionClass(ProbeItemsMigration::class))->getFileName();

        return hash('sha256', sprintf(
            '%s:%s:%s',
            ProbeItemsMigration::class,
            '20260101120000_create_items',
            (string) hash_file('sha256', $file),
        ));
    }

    /**
     * Parse the probe manifest declaring exactly one migration and no autoload roots.
     *
     * @return  ExtensionManifest  Canonical parsed manifest.
     *
     * @since   2.0.0
     */
    private static function manifest(): ExtensionManifest
    {
        return ExtensionManifest::fromJson((string) json_encode([
            'schema' => 3,
            'name' => 'acme/probe',
            'type' => 'component',
            'version' => '1.0.0',
            'provider' => 'Acme\\Probe\\Provider',
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.1.0'],
            'autoload' => ['psr-4' => ['Acme\\Probe\\Migrations\\' => 'src/']],
            'migrations' => [ProbeItemsMigration::class],
            'contributions' => ['version' => 1],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Materialize a real deployment directory carrying the declared autoload root.
     *
     * The declared migration class is already resident, so the directory only has to satisfy the
     * runner's symlink and containment checks on the PSR-4 root.
     *
     * @return  string  Absolute deployment directory path.
     *
     * @since   2.0.0
     */
    private static function deployment(): string
    {
        $root = sys_get_temp_dir() . '/kumwe-extension-migration-runner-test';
        if (!is_dir($root . '/src')) {
            mkdir($root . '/src', 0o775, true);
        }

        return $root;
    }

    /**
     * Build a fixed clock for deterministic ledger timestamps.
     *
     * @return  ClockInterface  Clock pinned to one instant.
     *
     * @since   2.0.0
     */
    private static function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            /**
             * Report the pinned probe instant.
             *
             * @return  DateTimeImmutable  Fixed timestamp.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-01-01T12:00:00+00:00');
            }
        };
    }
}

/**
 * Probe migration recording exactly which confined physical names the runner hands it.
 *
 * @since  2.0.0
 */
final class ProbeItemsMigration implements ExtensionMigration
{
    /**
     * Raw physical names observed by `up()`, in call order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public static array $rawNames = [];

    /**
     * Quoted physical names observed by `up()`, in call order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public static array $quotedNames = [];

    /**
     * Report the timestamped migration identifier.
     *
     * @return  string  Canonical migration ID.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return '20260101120000_create_items';
    }

    /**
     * Record the confined names the runner supplies instead of touching schema.
     *
     * @param   Connection           $database  Connection the migration would execute on.
     * @param   ExtensionTableNames  $tables    Confined per-extension name compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database, ExtensionTableNames $tables): void
    {
        unset($database);
        self::$rawNames[] = $tables->raw('items');
        self::$quotedNames[] = $tables->quoted('items');
    }

    /**
     * Record nothing on compensation; the probe never mutates schema.
     *
     * @param   Connection           $database  Connection the migration would execute on.
     * @param   ExtensionTableNames  $tables    Confined per-extension name compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function down(Connection $database, ExtensionTableNames $tables): void
    {
        unset($database, $tables);
    }
}
