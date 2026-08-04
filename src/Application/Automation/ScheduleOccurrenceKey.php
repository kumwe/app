<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ScheduleOccurrenceKey
{
    public static function for(string $scheduleId, DateTimeImmutable $occurrence): IdempotencyKey
    {
        if (trim($scheduleId) === '') {
            throw new InvalidArgumentException('A schedule ID is required.');
        }

        $instant = $occurrence->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $digest = CanonicalJson::digest([
            'occurrence' => $instant,
            'schedule_id' => $scheduleId,
        ]);

        return IdempotencyKey::fromString('schedule:' . $digest);
    }
}
