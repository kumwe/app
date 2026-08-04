<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Plan;

use DateInterval;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

final readonly class SafePlanFactory
{
    public function __construct(
        private ClockInterface $clock,
        private int $ttlSeconds = 900,
    ) {
        if ($ttlSeconds < 60 || $ttlSeconds > 3_600) {
            throw new \InvalidArgumentException('A safe plan TTL must be between 60 and 3600 seconds.');
        }
    }

    public function create(SafePlanOperation $operation, string $target): SafePlan
    {
        $createdAt = $this->clock->now();

        return new SafePlan(
            Uuid::uuid7($createdAt)->toString(),
            $operation,
            trim($target),
            $createdAt,
            $createdAt->add(new DateInterval(sprintf('PT%dS', $this->ttlSeconds))),
        );
    }
}
