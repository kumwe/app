<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Time;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock reading the host's wall clock, pinned to UTC.
 *
 * The container binds this as the `ClockInterface`, so the several dozen services that need a reading
 * share one clock rather than reaching for the current time themselves, and a test pins all of them by
 * substituting a fixed clock at that single seam. Pinning the zone here rather than at each call site is
 * what keeps stored timestamps, audit records and token expiry comparisons off the server's configured
 * timezone, so two nodes configured differently still agree on when something happened.
 *
 * @since  2.0.0
 */
final readonly class SystemClock implements ClockInterface
{
    /**
     * Reads the host clock.
     *
     * @return  DateTimeImmutable  The current instant in the UTC zone, whatever the process timezone
     *          is set to.
     *
     * @since   2.0.0
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
