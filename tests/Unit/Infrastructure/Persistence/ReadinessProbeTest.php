<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence;

use Joomla\Database\DatabaseInterface;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ReadinessProbe::class)]
final class ReadinessProbeTest extends TestCase
{
    public function testReportsReadyWhenLedgerAndRequiredMigrationExist(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->method('quote')->willReturnCallback(static fn (string $value): string => "'{$value}'");
        $database->method('quoteName')->willReturnCallback(static fn (string $value): string => '"' . $value . '"');
        $database->method('setQuery')->willReturnSelf();
        $database->expects(self::exactly(2))->method('loadResult')->willReturnOnConsecutiveCalls(
            true,
            '20260804000100_create_system_tables',
        );

        self::assertTrue((new ReadinessProbe(
            $database,
            new NullLogger(),
            'kumwe',
            '20260804000100_create_system_tables',
        ))->ready());
    }

    public function testReportsNotReadyWhenLedgerIsMissing(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->method('quote')->willReturn("'kumwe.schema_migrations'");
        $database->method('setQuery')->willReturnSelf();
        $database->method('loadResult')->willReturn(false);

        self::assertFalse((new ReadinessProbe(
            $database,
            new NullLogger(),
            'kumwe',
            '20260804000100_create_system_tables',
        ))->ready());
    }
}
