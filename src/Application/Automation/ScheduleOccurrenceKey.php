<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\Shared\Domain\CanonicalJson;

/**
 * Derives the key that lets one occurrence of one schedule be dispatched exactly once.
 *
 * `DoctrineScheduler` stamps this onto the job row it inserts for a due occurrence, where a unique index
 * turns a second scheduler process reaching the same occurrence into a swallowed constraint violation
 * rather than a duplicate job. That only holds if independent processes agree on the key byte for byte,
 * which is why the instant is normalised to UTC with microseconds before it is digested: the same moment
 * written in a site's local zone and in UTC produces one key, while two moments a microsecond apart
 * produce two.
 *
 * @since  2.0.0
 */
final class ScheduleOccurrenceKey
{
    /**
     * Build the key naming one occurrence of one schedule.
     *
     * @param   string             $scheduleId  Identifier of the schedule the occurrence belongs to.
     * @param   DateTimeImmutable  $occurrence  Moment the occurrence is due; only the instant it names
     *          affects the key, not the offset it is written in.
     *
     * @return  IdempotencyKey  A `schedule:` prefix followed by the digest of the schedule and instant.
     *
     * @throws  InvalidArgumentException  When the schedule identifier is blank, or carries bytes that cannot
     *          be encoded as canonical JSON.
     *
     * @since   2.0.0
     */
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
