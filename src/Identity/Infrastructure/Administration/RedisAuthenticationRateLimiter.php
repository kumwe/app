<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Administration;

use Kumwe\CMS\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;

/**
 * Redis-backed attempt budget that refuses a sign-in pair after ten counted tries in a 15-minute window.
 *
 * The counters belong in Redis rather than SQL because they are worthless once the window closes and
 * are written on every attempt, including the ones that never reach the database. Both digests are
 * joined into one key, so the budget is per account *and* origin: attempts from one origin never spend
 * another origin's tries against the same account, and one account's failures leave every other
 * account's budget intact. `RedisRuntime` hashes that key again before it becomes a Redis name, so
 * nothing an operator browsing the server reads back identifies an account. The window opens on the
 * first counted attempt and closes 900 seconds later whether or not attempts continue, and a success
 * clears it outright. An unreachable Redis fails closed, because the increment raises rather than
 * admitting an uncounted attempt.
 *
 * @since  2.0.0
 */
final readonly class RedisAuthenticationRateLimiter implements AuthenticationRateLimiter
{
    /**
     * Bind the limiter to the Redis runtime that holds its counters.
     *
     * @param  RedisRuntime  $redis  Typed wrapper over the shared client; it hashes and prefixes the key
     *         and owns the window's expiry.
     *
     * @since  2.0.0
     */
    public function __construct(private RedisRuntime $redis)
    {
    }

    /**
     * Count this attempt against the pair's window and refuse it once ten have been counted.
     *
     * The counter is incremented before the ceiling is judged, so the attempt that trips the limit is
     * itself counted and the eleventh attempt inside a window is the first to be refused. Counting is
     * unconditional, which is why `record()` has to clear the window after a successful sign-in.
     *
     * @param   string  $subjectDigest  Keyed digest of the normalised email being authenticated.
     * @param   string  $sourceDigest   Keyed digest of the origin the attempt arrives from.
     *
     * @return  void
     *
     * @throws  AuthenticationThrottled  When the pair has already counted ten attempts in this window.
     * @throws  \RuntimeException  When Redis cannot increment the counter, or cannot arm the expiry on a
     *          window this attempt opened.
     *
     * @since   2.0.0
     */
    public function assertAllowed(string $subjectDigest, string $sourceDigest): void
    {
        $attempt = $this->redis->incrementLimit($subjectDigest . ':' . $sourceDigest, 900);
        if ($attempt > 10) {
            throw new AuthenticationThrottled();
        }
    }

    /**
     * Clear the pair's window after a successful sign-in, and leave it standing after a failure.
     *
     * A failed attempt needs no write at all: `assertAllowed()` already counted it, and letting the
     * count stand is what walks a run of wrong passwords towards the ceiling. Clearing a window that
     * was never opened is a no-op, so the successful branch is safe whether or not anything failed
     * earlier.
     *
     * @param   string  $subjectDigest  Keyed digest of the normalised email that was authenticated.
     * @param   string  $sourceDigest   Keyed digest of the origin the attempt arrived from.
     * @param   bool    $succeeded      Whether the presented credential verified; only true writes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(string $subjectDigest, string $sourceDigest, bool $succeeded): void
    {
        if ($succeeded) {
            $this->redis->resetLimit($subjectDigest . ':' . $sourceDigest);
        }
    }
}
