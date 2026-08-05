<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Redis;

use RuntimeException;

final readonly class RedisLease
{
    public function __construct(
        private RedisRuntime $redis,
        private string $key,
        #[\SensitiveParameter] private string $token,
        private int $seconds,
    ) {
    }

    public function renew(): void
    {
        if (!$this->redis->renewLease($this->key, $this->token, $this->seconds)) {
            throw new RuntimeException('The extension registry lease was lost to a newer operation.');
        }
    }

    public function release(): void
    {
        $this->redis->releaseLease($this->key, $this->token);
    }
}
