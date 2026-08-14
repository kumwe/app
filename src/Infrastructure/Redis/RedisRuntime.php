<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Redis;

use JsonException;
use Redis;
use RuntimeException;

/**
 * Typed wrapper over the shared `Redis` client, exposing only the three jobs Kumwe gives Redis.
 *
 * Redis is used only for ephemeral cache, locks and limits; SQL remains the source of truth. Nothing
 * stored through here is authoritative: each kind of key gets its own prefix (`limit:`, `cache:`,
 * `lock:`) and its own expiry, so losing the server costs a rebuild rather than data. Keeping the
 * driver behind this class is what lets the collaborators above it — `RedisAuthenticationRateLimiter`,
 * `CachedSiteSettings`, `RedisLockedExtensionManager` — work in `int`, `array<string, mixed>` and
 * `RedisLease` instead of raw driver return values, and turns an unusable response into a
 * `RuntimeException` at this boundary rather than three call sites further in.
 *
 * @since  2.0.0
 */
final readonly class RedisRuntime
{
    /**
     * Bind the runtime to an already connected client.
     *
     * @param  Redis  $redis  Connected client from `RedisConnectionFactory`, with the deployment key
     *         prefix already installed.
     *
     * @since  2.0.0
     */
    public function __construct(private Redis $redis)
    {
    }

    /**
     * Reports whether the server still answers a ping.
     *
     * `ReadinessProbe` calls this, so a deployment whose Redis has gone away is held out of rotation
     * instead of failing on the first lock or cache read. The three accepted answers cover the shapes
     * different ext-redis versions return for `PING`.
     *
     * @return  bool  True when the server replied with a pong; false for any other reply.
     *
     * @since   2.0.0
     */
    public function ready(): bool
    {
        $response = $this->redis->ping();

        return $response === true || $response === '+PONG' || $response === 'PONG';
    }

    /**
     * Count one attempt against a rate-limit window and report the running total.
     *
     * The identifier is hashed before it becomes a key, so what is being limited — an account digest,
     * an origin digest — never reaches the Redis keyspace in a form an operator browsing the server
     * could read back. Only the attempt that opens a window sets its expiry, which makes it a fixed
     * window that closes a fixed time after the first attempt rather than one a steady stream of
     * attempts can hold open indefinitely.
     *
     * @param   string  $key      Identifier being limited; hashed before use, never stored as given.
     * @param   int     $seconds  Lifetime of the window, applied by the attempt that opens it.
     *
     * @return  int  Attempts recorded in the current window, this one included; the caller compares it
     *          against its own ceiling.
     *
     * @throws  RuntimeException  When the counter cannot be incremented, or a new window's expiry
     *          cannot be set.
     *
     * @since   2.0.0
     */
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

    /**
     * Drop a rate-limit window so the next attempt starts from zero.
     *
     * Called once an attempt has succeeded, so an operator's earlier typos do not keep counting
     * towards a lockout. Clearing a window that was never opened is a no-op.
     *
     * @param   string  $key  Identifier whose window is cleared; hashed exactly as when it was counted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function resetLimit(string $key): void
    {
        $this->redis->del('limit:' . hash('sha256', $key));
    }

    /**
     * Read a cached JSON document back as an associative array.
     *
     * A miss and a damaged entry are deliberately different outcomes: a miss answers null so the caller
     * recomputes from SQL, while a stored value that is not a JSON object is a fault worth raising
     * rather than quietly treating as absent, since it would otherwise be rewritten every read and
     * never noticed.
     *
     * @param   string  $key  Cache name, without the `cache:` prefix this method adds.
     *
     * @return  array<string, mixed>|null  The decoded document, or null when nothing is cached.
     *
     * @throws  JsonException  When the cached string is not valid JSON, or nests deeper than 64 levels.
     * @throws  RuntimeException  When Redis answers with a non-string value, or the decoded value is a
     *          list rather than a keyed document.
     *
     * @since   2.0.0
     */
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

    /**
     * Store a JSON document under a cache name with a fixed lifetime.
     *
     * There is no way to write a cache entry that never expires, which is what keeps a stale document
     * from outliving the row it was derived from when an invalidation is missed.
     *
     * @param   string                $key      Cache name, without the `cache:` prefix this method adds.
     * @param   array<string, mixed>  $value    Document to encode and store.
     * @param   int                   $seconds  Lifetime after which the entry disappears on its own.
     *
     * @return  void
     *
     * @throws  JsonException  When the document contains a value JSON cannot represent.
     * @throws  RuntimeException  When Redis refuses to store the entry.
     *
     * @since   2.0.0
     */
    public function cacheJson(string $key, array $value, int $seconds): void
    {
        $stored = $this->redis->setex('cache:' . $key, $seconds, json_encode($value, JSON_THROW_ON_ERROR));
        if (!$stored) {
            throw new RuntimeException('Redis could not store the cached value.');
        }
    }

    /**
     * Drop a cached document immediately.
     *
     * Writers call this after their database change has committed rather than before, so a rejected
     * write cannot leave readers on a cache that disagrees with the table. Forgetting a name that was
     * never cached is a no-op.
     *
     * @param   string  $key  Cache name, without the `cache:` prefix this method adds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function forgetCache(string $key): void
    {
        $this->redis->del('cache:' . $key);
    }

    /**
     * Try to take a named cross-process lock, without waiting for it.
     *
     * The existence check and the write run inside one Lua script, so two contenders cannot both find
     * the lock free and both take it. The lock is stamped with a freshly generated 256-bit token that
     * every later renewal and the release are checked against. Contention answers null instead of
     * queueing, so a second registry operation fails fast rather than piling up behind a long install,
     * and the lock carries an expiry so a crashed holder cannot lock the deployment out for good.
     *
     * @param   string  $key      Lock name, without the `lock:` prefix this method adds.
     * @param   int     $seconds  Lifetime of the lock, re-armed by `RedisLease::renew()`.
     *
     * @return  ?RedisLease  A handle on the lock now held, or null when another holder already has it.
     *
     * @throws  RuntimeException  When the script answers with neither a refusal nor a success.
     *
     * @since   2.0.0
     */
    public function acquireLease(string $key, int $seconds): ?RedisLease
    {
        $token = bin2hex(random_bytes(32));
        $script = "if redis.call('exists', KEYS[1]) == 1 then return false end "
            . "redis.call('set', KEYS[1], ARGV[1], 'EX', ARGV[2]); return 1";
        $result = $this->redis->eval(
            $script,
            ['lock:' . $key, $token, (string) $seconds],
            1,
        );
        if ($result === false || $result === null) {
            return null;
        }
        if ($result !== 1) {
            throw new RuntimeException('Redis returned an invalid extension registry lease result.');
        }

        return new RedisLease($this, $key, $token, $seconds);
    }

    /**
     * Re-arm a lock's expiry, but only for the holder whose token still matches.
     *
     * The comparison and the expiry run in one Lua script, so a lock that changed hands between the
     * two cannot be extended by its previous holder. The token is passed in rather than looked up
     * because the handle, not the runtime, is what remembers it; `RedisLease::renew()` is the only
     * caller, and the place where a refusal becomes an error.
     *
     * @param   string  $key      Lock name, without the `lock:` prefix this method adds.
     * @param   string  $token    Token minted when the lock was taken.
     * @param   int     $seconds  Lifetime to re-arm the lock with.
     *
     * @return  bool  True when the lock was still this holder's and its expiry moved; false when it
     *          had expired or now belongs to someone else.
     *
     * @since   2.0.0
     */
    public function renewLease(string $key, string $token, int $seconds): bool
    {
        $script = "if redis.call('get', KEYS[1]) == ARGV[1] then "
            . "return redis.call('expire', KEYS[1], ARGV[2]) else return 0 end";

        return $this->redis->eval(
            $script,
            ['lock:' . $key, $token, (string) $seconds],
            1,
        ) === 1;
    }

    /**
     * Delete a lock, but only while the caller's token still matches the stored one.
     *
     * The compare and the delete run in one Lua script, which is what makes releasing safe after an
     * expiry: a lease that lapsed and was re-taken elsewhere is left alone instead of being deleted out
     * from under its new holder.
     *
     * @param   string  $key    Lock name, without the `lock:` prefix this method adds.
     * @param   string  $token  Token minted when the lock was taken.
     *
     * @return  bool  True when this holder's lock was deleted; false when it had already expired or
     *          belongs to another holder.
     *
     * @since   2.0.0
     */
    public function releaseLease(string $key, string $token): bool
    {
        $script = "if redis.call('get', KEYS[1]) == ARGV[1] then "
            . "return redis.call('del', KEYS[1]) else return 0 end";

        return $this->redis->eval(
            $script,
            ['lock:' . $key, $token],
            1,
        ) === 1;
    }

    /**
     * Add to several metric fields in one round trip.
     *
     * Metrics are the fourth kind of key this class owns, and the only one that is deliberately not
     * expired: a counter that reset itself every few minutes would report a rate of zero for a healthy
     * system. It is still not authoritative — losing the server loses the counts, and a scrape simply
     * starts again from a lower value, which is the reset semantics every Prometheus client already
     * has. The whole batch is pipelined because the caller is on a request path: a histogram observation
     * updates its bucket, its sum and its count in one round trip rather than three.
     *
     * @param   array<string, float>  $increments  Amount to add, keyed by metric field name.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the server refuses the pipeline.
     *
     * @since   2.0.0
     */
    public function incrementMetrics(array $increments): void
    {
        if ($increments === []) {
            return;
        }
        $pipeline = $this->redis->multi(Redis::PIPELINE);
        foreach ($increments as $field => $value) {
            $pipeline->hIncrByFloat('metrics', $field, $value);
        }
        if ($pipeline->exec() === false) {
            throw new RuntimeException('Redis could not record metrics.');
        }
    }

    /**
     * Read every recorded metric field.
     *
     * @return  array<string, float>  Current value keyed by metric field name; empty when nothing is recorded.
     *
     * @throws  RuntimeException  When the server returns something other than a field map.
     *
     * @since   2.0.0
     */
    public function metrics(): array
    {
        $fields = $this->redis->hGetAll('metrics');
        if ($fields === false) {
            throw new RuntimeException('Redis could not read metrics.');
        }
        $values = [];
        foreach ($fields as $field => $value) {
            if (is_string($field) && (is_string($value) || is_int($value) || is_float($value))) {
                $values[$field] = (float) $value;
            }
        }

        return $values;
    }

    /**
     * Discard every recorded metric field.
     *
     * Only a test harness and an operator resetting a replaced deployment have any business calling
     * this; a counter an application resets on its own is not a counter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function forgetMetrics(): void
    {
        $this->redis->del('metrics');
    }
}
