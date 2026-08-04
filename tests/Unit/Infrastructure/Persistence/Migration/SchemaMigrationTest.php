<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use Kumwe\CMS\Infrastructure\Persistence\Migration\SchemaMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaMigration::class)]
final class SchemaMigrationTest extends TestCase
{
    public function testItExecutesStatementsInTheConfiguredSchemaAndSkipsTransactionMarkers(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('quoteName')->with('kumwe')->willReturn('"kumwe"');
        $database->expects(self::exactly(2))
            ->method('setQuery')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, '"kumwe"')))
            ->willReturnSelf();
        $database->expects(self::exactly(2))->method('execute');

        $migration = new SchemaMigration(
            '20260804000300_test_schema',
            'kumwe',
            'BEGIN; CREATE TABLE {{schema}}.one (id int); CREATE TABLE {{schema}}.two (id int); COMMIT;',
        );

        $migration->up($database);
    }

    public function testItRejectsSqlWithoutTheSchemaPlaceholder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SchemaMigration('20260804000300_test_schema', 'kumwe', 'SELECT 1;');
    }
}
