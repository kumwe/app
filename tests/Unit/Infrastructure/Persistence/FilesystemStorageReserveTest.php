<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence;

use InvalidArgumentException;
use Kumwe\App\Infrastructure\Persistence\FilesystemStorageReserve;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the 30% free-space guardrail: measured when configured, silent when not, refusing a bad reserve.
 *
 * @since  2.0.0
 */
#[CoversClass(FilesystemStorageReserve::class)]
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
}
