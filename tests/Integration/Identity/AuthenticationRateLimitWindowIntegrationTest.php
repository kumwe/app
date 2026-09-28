<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Identity;

use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Identity\Infrastructure\Administration\RedisAuthenticationRateLimiter;
use Kumwe\App\Infrastructure\Redis\RedisConnectionFactory;
use Kumwe\App\Infrastructure\Redis\RedisRuntime;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Kernel\Configuration\RedisConfiguration;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Proves the sign-in budget's fixed window against a real Redis server.
 *
 * The unit test pins the arithmetic over a stand-in client; this one proves the server keeps the
 * promise: the key exists only under a hashed name, its time-to-live is armed at fifteen minutes by the
 * first attempt and counts down through later attempts instead of being re-armed, the eleventh attempt
 * inside the window is refused, and a success removes the key so the next attempt opens a fresh
 * fifteen-minute window.
 *
 * @since  2.0.0
 */
#[CoversClass(RedisAuthenticationRateLimiter::class)]
#[CoversClass(RedisRuntime::class)]
final class AuthenticationRateLimitWindowIntegrationTest extends TestCase
{
    /**
     * Client bound to a drill-suffixed namespace of the deployment's Redis.
     *
     * @var    ?Redis
     * @since  2.0.0
     */
    private ?Redis $redis = null;

    /**
     * Keys this test created, removed on teardown.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $keys = [];

    /**
     * Connect to the deployment's Redis under a namespace suffix reserved for this drill.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $upstream = (new ConfigurationFactory())->create(Environment::fromGlobals())->redis;
        $this->redis = (new RedisConnectionFactory(new RedisConfiguration(
            $upstream->host,
            $upstream->port,
            $upstream->password,
            $upstream->database,
            $upstream->namespace . '.rate-limit-drill',
        )))->create();
    }

    /**
     * Remove the drill's keys and close the client.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $redis = $this->client();
        foreach ($this->keys as $key) {
            $redis->del($key);
        }
        $redis->close();
    }

    /**
     * Answer the connected drill client.
     *
     * @return  Redis  Client opened in `setUp()`.
     *
     * @since   2.0.0
     */
    private function client(): Redis
    {
        self::assertInstanceOf(Redis::class, $this->redis);

        return $this->redis;
    }

    /**
     * The window is armed once at 900 seconds, never re-armed, refuses the eleventh attempt and clears on success.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheFixedWindowClosesFifteenMinutesAfterTheFirstAttemptAndASuccessClearsIt(): void
    {
        $redis = $this->client();
        $limiter = new RedisAuthenticationRateLimiter(new RedisRuntime($redis));
        $subject = 'rate-limit-subject-' . bin2hex(random_bytes(6));
        $source = 'rate-limit-source-' . bin2hex(random_bytes(6));
        $key = 'limit:' . hash('sha256', $subject . ':' . $source);
        $this->keys[] = $key;

        $limiter->assertAllowed($subject, $source);
        self::assertSame(1, $redis->exists($key), 'The window lives under the hashed pair name.');
        self::assertSame(0, $redis->exists('limit:' . $subject . ':' . $source), 'The digests never appear.');
        $armed = $redis->ttl($key);
        self::assertIsInt($armed);
        self::assertGreaterThan(890, $armed);
        self::assertLessThanOrEqual(900, $armed);

        usleep(1_100_000);
        for ($attempt = 2; $attempt <= 10; ++$attempt) {
            $limiter->assertAllowed($subject, $source);
        }
        $later = $redis->ttl($key);
        self::assertIsInt($later);
        self::assertLessThan($armed, $later, 'Later attempts count down the window; none re-arms it.');
        self::assertSame('10', $redis->get($key));

        try {
            $limiter->assertAllowed($subject, $source);
            self::fail('The eleventh attempt inside the window must be refused.');
        } catch (AuthenticationThrottled) {
            self::assertSame('11', $redis->get($key), 'The refused attempt is counted too.');
        }

        $limiter->record($subject, $source, false);
        self::assertSame('11', $redis->get($key), 'A failure leaves the window standing.');

        $limiter->record($subject, $source, true);
        self::assertSame(0, $redis->exists($key), 'A success clears the window outright.');

        $limiter->assertAllowed($subject, $source);
        self::assertSame('1', $redis->get($key), 'The next attempt opens a fresh window from one.');
        $reopened = $redis->ttl($key);
        self::assertIsInt($reopened);
        self::assertGreaterThan($later, $reopened, 'The fresh window is armed anew at fifteen minutes.');
    }
}
