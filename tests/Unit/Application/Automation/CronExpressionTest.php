<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\CronExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CronExpression::class)]
final class CronExpressionTest extends TestCase
{
    public function testFindsTheNextSteppedOccurrenceInUtc(): void
    {
        $cron = new CronExpression('*/15 * * * *');

        self::assertSame(
            '2026-08-04T12:15:00+00:00',
            $cron->next(new DateTimeImmutable('2026-08-04T12:01:30+00:00'), 'UTC')->format(DATE_ATOM),
        );
    }

    public function testEvaluatesOccurrencesInTheConfiguredTimezone(): void
    {
        $cron = new CronExpression('0 8 * * 1-5');

        self::assertSame(
            '2026-08-05T06:00:00+00:00',
            $cron->next(new DateTimeImmutable('2026-08-04T07:00:00+00:00'), 'Africa/Windhoek')->format(DATE_ATOM),
        );
    }

    public function testRejectsOutOfRangeFields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CronExpression('60 * * * *');
    }
}
