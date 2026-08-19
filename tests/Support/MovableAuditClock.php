<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** Clock a test can move forward so settle windows and retention cutoffs are reached deterministically. */
final class MovableAuditClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $instant)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function advance(string $interval): void
    {
        $this->instant = $this->instant->modify($interval);
    }
}
