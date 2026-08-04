<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\ScheduleOccurrenceKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScheduleOccurrenceKey::class)]
final class ScheduleOccurrenceKeyTest extends TestCase
{
    public function testSameInstantProducesSameKeyAcrossTimeZones(): void
    {
        $utc = ScheduleOccurrenceKey::for(
            'schedule-1',
            new DateTimeImmutable('2026-08-04T12:00:00.123456+00:00'),
        );
        $namibia = ScheduleOccurrenceKey::for(
            'schedule-1',
            new DateTimeImmutable('2026-08-04T14:00:00.123456+02:00'),
        );

        self::assertTrue($utc->equals($namibia));
        self::assertFalse($utc->equals(ScheduleOccurrenceKey::for(
            'schedule-1',
            new DateTimeImmutable('2026-08-04T12:01:00.123456+00:00'),
        )));
    }

    public function testMissingScheduleIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScheduleOccurrenceKey::for('', new DateTimeImmutable());
    }
}
