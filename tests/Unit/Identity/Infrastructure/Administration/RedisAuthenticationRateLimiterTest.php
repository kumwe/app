<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Infrastructure\Administration;

use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Identity\Infrastructure\Administration\RedisAuthenticationRateLimiter;
use Kumwe\App\Infrastructure\Redis\RedisRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use RuntimeException;

/**
 * Pins the sign-in budget's window arithmetic against an in-memory stand-in for the Redis client.
 *
 * The limiter's contract is small and every part of it is security-bearing: ten counted attempts are
 * admitted and the eleventh is refused; the window is armed once, by the attempt that opens it, and is
 * never extended by later attempts; the budget belongs to one account and origin pair under a hashed
 * key; a success clears the window while a failure leaves it counting; and a server that cannot count
 * an attempt refuses it as an outage rather than admitting it or reporting it as throttling.
 *
 * @since  2.0.0
 */
#[CoversClass(RedisAuthenticationRateLimiter::class)]
#[CoversClass(RedisRuntime::class)]
final class RedisAuthenticationRateLimiterTest extends TestCase
{
    /**
     * Keyed digest standing in for the normalised email being authenticated.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SUBJECT = 'subject-digest-a';

    /**
     * Keyed digest standing in for the origin the attempts arrive from.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SOURCE = 'source-digest-a';

    /**
     * Ten attempts are counted and admitted; the eleventh inside the same window is the first refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheTenthAttemptIsAdmittedAndTheEleventhIsRefused(): void
    {
        $redis = new CountingRedis();
        $limiter = new RedisAuthenticationRateLimiter(new RedisRuntime($redis));

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
        }
        self::assertSame(10, $redis->counters[self::key(self::SUBJECT, self::SOURCE)]);

        try {
            $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
            self::fail('The eleventh attempt inside one window must be refused.');
        } catch (AuthenticationThrottled $throttled) {
            self::assertSame(
                'Too many unsuccessful authentication attempts. Try again later.',
                $throttled->getMessage(),
            );
        }
        self::assertSame(
            11,
            $redis->counters[self::key(self::SUBJECT, self::SOURCE)],
            'The refused attempt is itself counted, so a caller hammering the door keeps spending.',
        );
    }

    /**
     * Only the attempt that opens a window arms its 900-second expiry; later attempts never extend it.
     *
     * A steady stream of wrong passwords must not hold the window open indefinitely, which is what a
     * sliding expiry would do. The fixed window closes fifteen minutes after the first counted attempt
     * whatever follows it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheWindowExpiryIsArmedOnlyByTheAttemptThatOpensIt(): void
    {
        $redis = new CountingRedis();
        $limiter = new RedisAuthenticationRateLimiter(new RedisRuntime($redis));

        $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
        $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
        $limiter->assertAllowed(self::SUBJECT, self::SOURCE);

        self::assertSame([[self::key(self::SUBJECT, self::SOURCE), 900]], $redis->expiries);
    }

    /**
     * Each account and origin pair spends its own budget, under a key that discloses neither digest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBudgetsAreKeyedPerSubjectAndOriginPairUnderAHashedName(): void
    {
        $redis = new CountingRedis();
        $limiter = new RedisAuthenticationRateLimiter(new RedisRuntime($redis));

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
        }

        $limiter->assertAllowed(self::SUBJECT, 'source-digest-b');
        $limiter->assertAllowed('subject-digest-b', self::SOURCE);
        self::assertSame(1, $redis->counters[self::key(self::SUBJECT, 'source-digest-b')]);
        self::assertSame(1, $redis->counters[self::key('subject-digest-b', self::SOURCE)]);
        self::assertSame(
            1,
            $redis->counters[self::key(self::SUBJECT, 'source-digest-b')],
            'A second origin against the same account starts its own window.',
        );

        foreach (array_keys($redis->counters) as $key) {
            self::assertMatchesRegularExpression('/^limit:[0-9a-f]{64}$/D', $key);
            self::assertStringNotContainsString(self::SUBJECT, $key);
            self::assertStringNotContainsString(self::SOURCE, $key);
        }
    }

    /**
     * A successful sign-in clears the pair's window and a failed one writes nothing, leaving it standing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASuccessfulSignInClearsTheWindowAndAFailureLeavesItStanding(): void
    {
        $redis = new CountingRedis();
        $limiter = new RedisAuthenticationRateLimiter(new RedisRuntime($redis));

        for ($attempt = 1; $attempt <= 9; ++$attempt) {
            $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
            $limiter->record(self::SUBJECT, self::SOURCE, false);
        }
        self::assertSame([], $redis->deleted, 'A failure needs no write; the counted attempt already stands.');
        self::assertSame(9, $redis->counters[self::key(self::SUBJECT, self::SOURCE)]);

        $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
        $limiter->record(self::SUBJECT, self::SOURCE, true);
        self::assertSame([self::key(self::SUBJECT, self::SOURCE)], $redis->deleted);
        self::assertArrayNotHasKey(self::key(self::SUBJECT, self::SOURCE), $redis->counters);

        $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
        self::assertSame(
            1,
            $redis->counters[self::key(self::SUBJECT, self::SOURCE)],
            'The window after a success starts from zero rather than from the cleared count.',
        );
        self::assertCount(2, $redis->expiries, 'The reopened window is armed afresh.');
    }

    /**
     * Clearing a window that was never opened is a harmless no-op rather than an error.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testClearingAWindowThatWasNeverOpenedIsANoOp(): void
    {
        $redis = new CountingRedis();
        $limiter = new RedisAuthenticationRateLimiter(new RedisRuntime($redis));

        $limiter->record(self::SUBJECT, self::SOURCE, true);

        self::assertSame([self::key(self::SUBJECT, self::SOURCE)], $redis->deleted);
        self::assertSame([], $redis->counters);
    }

    /**
     * An increment reply that is not a count is a failure to count, never an admission.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnusableIncrementReplyIsAFailureNotAnAdmission(): void
    {
        $redis = new CountingRedis();
        $redis->incrementReply = false;
        $limiter = new RedisAuthenticationRateLimiter(new RedisRuntime($redis));

        try {
            $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
            self::fail('An attempt that could not be counted must not be admitted.');
        } catch (RuntimeException $failure) {
            self::assertSame('Redis could not update a rate limit.', $failure->getMessage());
        }
    }

    /**
     * A window whose expiry cannot be armed is refused, because it would otherwise never close.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAWindowWhoseExpiryCannotBeArmedIsRefused(): void
    {
        $redis = new CountingRedis();
        $redis->expireSucceeds = false;
        $limiter = new RedisAuthenticationRateLimiter(new RedisRuntime($redis));

        try {
            $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
            self::fail('A window that cannot close must not be opened.');
        } catch (RuntimeException $failure) {
            self::assertSame('Redis could not set the rate-limit expiry.', $failure->getMessage());
        }
    }

    /**
     * An unreachable server is reported as an outage, distinct from throttling, and admits nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnreachableServerIsReportedAsAnOutageNotAsThrottling(): void
    {
        $redis = new CountingRedis();
        $redis->failure = new RedisException('Connection lost');
        $limiter = new RedisAuthenticationRateLimiter(new RedisRuntime($redis));

        try {
            $limiter->assertAllowed(self::SUBJECT, self::SOURCE);
            self::fail('An attempt that cannot be counted must not be admitted.');
        } catch (AuthenticationThrottled) {
            self::fail('An outage must not be reported as a spent budget.');
        } catch (RuntimeException $failure) {
            self::assertSame('Redis is unreachable.', $failure->getMessage());
            self::assertSame($redis->failure, $failure->getPrevious());
        }

        try {
            $limiter->record(self::SUBJECT, self::SOURCE, true);
            self::fail('A sign-in whose window cannot be cleared must be refused rather than completed.');
        } catch (RuntimeException $failure) {
            self::assertSame('Redis is unreachable.', $failure->getMessage());
        }
    }

    /**
     * Spell the Redis key the runtime derives for one account and origin pair.
     *
     * @param   string  $subject  Keyed subject digest.
     * @param   string  $source   Keyed origin digest.
     *
     * @return  string  The `limit:` key after the runtime hashes the joined pair.
     *
     * @since   2.0.0
     */
    private static function key(string $subject, string $source): string
    {
        return 'limit:' . hash('sha256', $subject . ':' . $source);
    }
}

/**
 * In-memory `Redis` client recording exactly the three commands the rate limiter is allowed to issue.
 *
 * @since  2.0.0
 */
final class CountingRedis extends Redis
{
    /**
     * Running counter per key, as `INCR` would keep it.
     *
     * @var    array<string, int>
     * @since  2.0.0
     */
    public array $counters = [];

    /**
     * Every `EXPIRE` issued, as key and seconds pairs in call order.
     *
     * @var    list<array{string, int}>
     * @since  2.0.0
     */
    public array $expiries = [];

    /**
     * Every key handed to `DEL`, in call order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $deleted = [];

    /**
     * Whether `EXPIRE` reports success.
     *
     * @var    bool
     * @since  2.0.0
     */
    public bool $expireSucceeds = true;

    /**
     * Reply `INCR` answers with instead of a count, or null to count normally.
     *
     * @var    ?false
     * @since  2.0.0
     */
    public ?bool $incrementReply = null;

    /**
     * Driver failure every command raises, or null for a reachable server.
     *
     * @var    ?RedisException
     * @since  2.0.0
     */
    public ?RedisException $failure = null;

    /**
     * Build the double without opening a connection.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Count one attempt, or answer the configured substitute reply.
     *
     * @param   string  $key  Counter name.
     * @param   int     $by   Increment, always one from the runtime.
     *
     * @return  int|false  The running count, or false when the double is configured to fail the increment.
     *
     * @throws  RedisException  When the double is configured as unreachable.
     *
     * @since   2.0.0
     */
    public function incr(string $key, int $by = 1): int|false
    {
        $this->raiseWhenUnreachable();
        if ($this->incrementReply !== null) {
            return false;
        }
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $by;

        return $this->counters[$key];
    }

    /**
     * Record the expiry a window is armed with.
     *
     * @param   string   $key      Counter name.
     * @param   int      $timeout  Seconds until the window closes.
     * @param   ?string  $mode     Unused conditional mode.
     *
     * @return  bool  Whether the expiry was accepted.
     *
     * @throws  RedisException  When the double is configured as unreachable.
     *
     * @since   2.0.0
     */
    public function expire(string $key, int $timeout, ?string $mode = null): bool
    {
        $this->raiseWhenUnreachable();
        $this->expiries[] = [$key, $timeout];

        return $this->expireSucceeds;
    }

    /**
     * Drop counters, recording each key removed.
     *
     * @param   array<int, string>|string  $key         First key to remove.
     * @param   string                     $other_keys  Further keys to remove.
     *
     * @return  int  Number of keys that existed.
     *
     * @throws  RedisException  When the double is configured as unreachable.
     *
     * @since   2.0.0
     */
    public function del(array|string $key, string ...$other_keys): int
    {
        $this->raiseWhenUnreachable();
        $removed = 0;
        foreach ([...(is_array($key) ? array_values($key) : [$key]), ...$other_keys] as $name) {
            $this->deleted[] = $name;
            if (isset($this->counters[$name])) {
                unset($this->counters[$name]);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * Raise the configured driver failure, if any.
     *
     * @return  void
     *
     * @throws  RedisException  When the double is configured as unreachable.
     *
     * @since   2.0.0
     */
    private function raiseWhenUnreachable(): void
    {
        if ($this->failure instanceof RedisException) {
            throw $this->failure;
        }
    }
}
