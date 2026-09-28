<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Persistence\DoctrineLargestTable;
use Kumwe\App\Infrastructure\Persistence\FilesystemStorageReserve;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the 30% free-space guardrail: measured when configured, silent when not, refusing a bad reserve.
 *
 * @since  2.0.0
 */
#[CoversClass(FilesystemStorageReserve::class)]
#[CoversClass(DoctrineLargestTable::class)]
final class FilesystemStorageReserveTest extends TestCase
{
    /**
     * A visible volume is measured against the reserve; an unconfigured or missing path reports nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheReserveIsMeasuredOnlyWhenTheVolumeIsVisible(): void
    {
        $report = (new FilesystemStorageReserve(sys_get_temp_dir()))->report();
        self::assertNotNull($report);
        self::assertGreaterThan(0, $report['total_bytes']);
        self::assertSame($report['free_fraction'] >= 0.30, $report['satisfied']);
        self::assertFalse((new FilesystemStorageReserve(sys_get_temp_dir(), 0.9999))->report()['satisfied'] ?? true);
        self::assertNull((new FilesystemStorageReserve(null))->report());
        self::assertNull((new FilesystemStorageReserve('/nonexistent/kumwe/volume'))->report());
        $this->expectException(InvalidArgumentException::class);
        new FilesystemStorageReserve(null, 1.0);
    }

    /**
     * The volume must also keep twice the largest table free for its rebuild, whatever the free fraction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheLargestTablesRebuildSpaceMustStayFree(): void
    {
        $small = (new FilesystemStorageReserve(sys_get_temp_dir(), 0.0001, $this->largest(1_000, false)))->report();
        self::assertNotNull($small);
        self::assertSame(1_000, $small['largest_table_bytes']);
        self::assertSame(2_000, $small['rebuild_bytes_required']);
        self::assertTrue($small['satisfied']);
        $huge = (new FilesystemStorageReserve(
            sys_get_temp_dir(),
            0.0001,
            $this->largest(PHP_INT_MAX >> 2, true),
        ))->report();
        self::assertNotNull($huge);
        self::assertFalse($huge['satisfied'], 'A volume that cannot hold a rebuild of the largest table fails.');
        $unknown = (new FilesystemStorageReserve(sys_get_temp_dir(), 0.0001))->report();
        self::assertNotNull($unknown);
        self::assertNull($unknown['rebuild_bytes_required']);
    }

    /**
     * Build a largest-table probe over a connection stub that reports the given size.
     *
     * @param   int   $bytes     Size the catalogue reports.
     * @param   bool  $postgres  Whether to present the PostgreSQL catalogue.
     *
     * @return  DoctrineLargestTable  Probe.
     *
     * @since   2.0.0
     */
    private function largest(int $bytes, bool $postgres): DoctrineLargestTable
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(
            $postgres ? new PostgreSQLPlatform() : new MariaDBPlatform(),
        );
        $connection->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $parameters) use ($bytes, $postgres): string {
                self::assertSame($postgres, str_contains($sql, 'pg_total_relation_size'));
                self::assertSame(['kumwe\\_%'], $parameters, 'The prefix is matched literally.');

                return (string) $bytes;
            },
        );

        return new DoctrineLargestTable($connection, new TableNames($connection, 'kumwe_'));
    }
}
