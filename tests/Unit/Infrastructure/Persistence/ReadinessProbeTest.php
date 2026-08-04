<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ReadinessProbe::class)]
final class ReadinessProbeTest extends TestCase
{
    public function testReportsReadyWhenLedgerAndRequiredMigrationExist(): void
    {
        $schema = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(true);
        $database = $this->createMock(Connection::class);
        $database->method('createSchemaManager')->willReturn($schema);
        $database->method('quoteSingleIdentifier')->willReturn('"kumwe_schema_migrations"');
        $database->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls(
            1,
            '20260804000100_create_system_tables',
        );

        self::assertTrue((new ReadinessProbe(
            $database,
            new NullLogger(),
            new TableNames($database, 'kumwe_'),
            '20260804000100_create_system_tables',
        ))->ready());
    }

    public function testReportsNotReadyWhenLedgerIsMissing(): void
    {
        $schema = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(false);
        $database = $this->createMock(Connection::class);
        $database->method('createSchemaManager')->willReturn($schema);
        $database->method('fetchOne')->willReturn(1);

        self::assertFalse((new ReadinessProbe(
            $database,
            new NullLogger(),
            new TableNames($database, 'kumwe_'),
            '20260804000100_create_system_tables',
        ))->ready());
    }
}
