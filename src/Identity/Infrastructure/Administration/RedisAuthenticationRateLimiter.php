<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Administration;

use Kumwe\CMS\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;

final readonly class RedisAuthenticationRateLimiter implements AuthenticationRateLimiter
{
    public function __construct(private RedisRuntime $redis)
    {
    }

    public function assertAllowed(string $subjectDigest, string $sourceDigest): void
    {
        $attempt = $this->redis->incrementLimit($subjectDigest . ':' . $sourceDigest, 900);
        if ($attempt > 10) {
            throw new AuthenticationThrottled();
        }
    }

    public function record(string $subjectDigest, string $sourceDigest, bool $succeeded): void
    {
        if ($succeeded) {
            $this->redis->resetLimit($subjectDigest . ':' . $sourceDigest);
        }
    }
}
