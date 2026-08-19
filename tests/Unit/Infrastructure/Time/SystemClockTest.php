<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Time;

use DateTimeImmutable;
use DateTimeZone;
use Kumwe\App\Infrastructure\Time\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testItReturnsTheCurrentTimeInUtc(): void
    {
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $actual = (new SystemClock())->now();
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertSame('UTC', $actual->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $actual);
        self::assertLessThanOrEqual($after, $actual);
    }
}
