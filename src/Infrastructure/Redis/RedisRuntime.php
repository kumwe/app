<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Redis;

use JsonException;
use Redis;
use RuntimeException;

/** Redis is used only for ephemeral cache, locks and limits; SQL remains the source of truth. */
final readonly class RedisRuntime
{
    public function __construct(private Redis $redis)
    {
    }

    public function ready(): bool
    {
        $response = $this->redis->ping();

        return $response === true || $response === '+PONG' || $response === 'PONG';
    }

    public function incrementLimit(string $key, int $seconds): int
    {
        $count = $this->redis->incr('limit:' . hash('sha256', $key));
        if (!is_int($count)) {
            throw new RuntimeException('Redis could not update a rate limit.');
        }
        if ($count === 1 && !$this->redis->expire('limit:' . hash('sha256', $key), $seconds)) {
            throw new RuntimeException('Redis could not set the rate-limit expiry.');
        }

        return $count;
    }

    public function resetLimit(string $key): void
    {
        $this->redis->del('limit:' . hash('sha256', $key));
    }

    /** @return array<string, mixed>|null */
    public function cachedJson(string $key): ?array
    {
        $value = $this->redis->get('cache:' . $key);
        if ($value === false) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Redis returned an invalid cached value.');
        }
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('A cached Kumwe value is invalid.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed> $value @throws JsonException */
    public function cacheJson(string $key, array $value, int $seconds): void
    {
        $stored = $this->redis->setex('cache:' . $key, $seconds, json_encode($value, JSON_THROW_ON_ERROR));
        if (!$stored) {
            throw new RuntimeException('Redis could not store the cached value.');
        }
    }

    public function forgetCache(string $key): void
    {
        $this->redis->del('cache:' . $key);
    }

    public function acquireLock(string $key, string $token, int $seconds): bool
    {
        return $this->redis->set('lock:' . $key, $token, ['nx', 'ex' => $seconds]) === true;
    }

    public function releaseLock(string $key, string $token): bool
    {
        $script = "if redis.call('get', KEYS[1]) == ARGV[1] then "
            . "return redis.call('del', KEYS[1]) else return 0 end";
        $result = $this->redis->eval($script, ['lock:' . $key, $token], 1);

        return $result === 1;
    }
}
