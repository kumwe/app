<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Redis;

use RuntimeException;

/**
 * Ownership-checked handle on one held Redis lock, handed back by `RedisRuntime::acquireLease()`.
 *
 * The secret minted when the lock was taken travels with the handle, and both operations on it compare
 * that secret against what Redis currently stores before acting. That is what makes a lease safe to
 * hold across a long operation: a holder whose lock expired and was re-taken elsewhere finds out on its
 * next `renew()`, and its `release()` cannot delete the newer holder's lock. Callers never mint one
 * themselves: the runtime hands it back on a successful acquisition, and both registry paths —
 * extension installation and administrator theme recovery — wrap it in a
 * `DatabaseFencedExtensionRegistryLease` and release it on the failure path as well as the success one.
 *
 * @since  2.0.0
 */
final readonly class RedisLease
{
    /**
     * Capture the lock a successful acquisition established.
     *
     * @param  RedisRuntime  $redis    Runtime owning the connection the lock lives on.
     * @param  string        $key      Lock name, without the `lock:` prefix the runtime adds.
     * @param  string        $token    Secret minted at acquisition, proving this holder still owns the
     *         lock.
     * @param  int           $seconds  Lifetime each renewal re-arms the lock with.
     *
     * @since  2.0.0
     */
    public function __construct(
        private RedisRuntime $redis,
        private string $key,
        #[\SensitiveParameter] private string $token,
        private int $seconds,
    ) {
    }

    /**
     * Push the lock's expiry out by another full lifetime.
     *
     * Long-running work calls this to keep its lock alive. Losing the lease is raised rather than
     * ignored, so an operation that has already been overtaken by another one stops instead of
     * continuing to write under a lock it no longer holds.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the lock had expired or is now held by a newer operation.
     *
     * @since   2.0.0
     */
    public function renew(): void
    {
        if (!$this->redis->renewLease($this->key, $this->token, $this->seconds)) {
            throw new RuntimeException('The extension registry lease was lost to a newer operation.');
        }
    }

    /**
     * Give the lock up, provided it is still this holder's.
     *
     * Safe to call on a lease that already expired or was lost: the delete is conditional on the
     * token, so a newer holder's lock is left standing, and the outcome is deliberately not reported —
     * a release that finds nothing of its own to delete has still achieved what the caller wanted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function release(): void
    {
        $this->redis->releaseLease($this->key, $this->token);
    }
}
