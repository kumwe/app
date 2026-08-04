<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Time;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
