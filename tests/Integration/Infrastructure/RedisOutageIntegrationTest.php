<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Infrastructure;

use Kumwe\App\Kernel\Container;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAdministratorIdentityGateway;
use Kumwe\App\Identity\Infrastructure\Administration\RedisAuthenticationRateLimiter;
use Kumwe\App\Infrastructure\Redis\RedisConnectionFactory;
use Kumwe\App\Infrastructure\Redis\RedisRuntime;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Kernel\Configuration\RedisConfiguration;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Site\Infrastructure\Persistence\CachedSiteSettings;
use Kumwe\App\Site\Infrastructure\Persistence\DoctrineSiteSettings;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Redis;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Stringable;

/**
 * Takes Redis away from a running installation and proves the two opposite promises made about it.
 *
 * Redis carries a security control and a performance cache in the same server, and the documentation
 * promises opposite behaviour for each: the sign-in budget fails *closed*, because admitting an attempt
 * nobody counted loses the control outright, while the settings cache is non-authoritative and must not
 * turn its own outage into a site-wide failure. Neither promise had ever been executed. This drill
 * executes both against a real client and a real server, with the outage produced by killing a relay
 * process the connection runs through — so the client's own reconnect attempts are refused exactly as
 * they are when the server is down, rather than a stub deciding what failure looks like.
 *
 * The recovery half records something an operator needs to know: an established client does not heal
 * when the server comes back. Readiness reports the outage instead of raising, which is what drains a
 * replica, and the connection is replaced when the process is — a request-scoped container gets a new
 * one on its next request, and a long-lived worker gets one when the supervisor restarts it.
 *
 * @since  2.0.0
 */
#[CoversClass(RedisRuntime::class)]
#[CoversClass(RedisAuthenticationRateLimiter::class)]
#[CoversClass(CachedSiteSettings::class)]
#[CoversClass(DoctrineAdministratorIdentityGateway::class)]
final class RedisOutageIntegrationTest extends TestCase
{
    public function testTheSignInBudgetFailsClosedAndThePageCacheDegradesWhileRedisIsGone(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $upstream = $this->upstream($container);
        $room = sys_get_temp_dir() . '/kumwe-redis-outage-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($room, 0o700, true));
        $port = $this->freePort();
        $relay = null;

        try {
            $relay = $this->startRelay($port, $room, $upstream);
            $runtime = $this->runtimeThrough($port, $upstream);
            $limiter = new RedisAuthenticationRateLimiter($runtime);
            $logger = new RecordingLogger();
            $settings = new CachedSiteSettings($this->siteSettings($container), $runtime, $logger);
            $gateway = $this->gatewayWith($container, $limiter);
            $subject = 'wave-six-subject-' . bin2hex(random_bytes(4));
            $origin = 'wave-six-origin-' . bin2hex(random_bytes(4));

            self::assertTrue($runtime->ready(), 'The drill needs a healthy Redis before it takes one away.');
            $limiter->assertAllowed($subject, $origin);
            $warm = $settings->current();
            self::assertNotSame([], $warm);
            self::assertSame($warm, $settings->current(), 'The warmed cache must answer the second read.');
            self::assertSame([], $logger->warnings, 'A healthy cache records no degradation.');

            self::assertTrue(posix_kill($relay['pid'], SIGKILL));
            $relay = null;
            usleep(300_000);

            self::assertFalse($runtime->ready(), 'A dead Redis must read as not-ready, not raise at the probe.');

            try {
                $limiter->assertAllowed($subject, $origin);
                self::fail('An uncounted attempt must never be admitted while the budget cannot be counted.');
            } catch (AuthenticationThrottled) {
                self::fail('An outage must not be reported as a spent budget, which callers already absorb.');
            } catch (RuntimeException $failure) {
                self::assertSame('Redis is unreachable.', $failure->getMessage());
            }

            // The same refusal, reached the way a sign-in reaches it: through the real gateway, before
            // any credential is looked at. The login handlers catch only `AuthenticationThrottled`, so
            // this is what keeps an outage from becoming an open door.
            try {
                $gateway->authenticate('wave-six-drill@example.test', 'not-the-password', $origin);
                self::fail('A sign-in must not proceed while its attempt budget is unreachable.');
            } catch (AuthenticationThrottled) {
                self::fail('An outage must not be reported to the caller as ordinary throttling.');
            } catch (RuntimeException $failure) {
                self::assertSame('Redis is unreachable.', $failure->getMessage());
            }

            // The cache makes the opposite promise, and keeps it: SQL is authoritative, so a public read
            // costs a query instead of failing.
            self::assertSame($warm, $settings->current(), 'A dead cache must not stop the authoritative read.');
            self::assertNotSame([], $logger->warnings, 'A degraded read must be visible to an operator.');
            self::assertStringContainsString('cache is unavailable', $logger->warnings[0]);

            self::assertNull(
                $this->leaseFailure($runtime, 'wave-six-outage-' . bin2hex(random_bytes(4))),
                'A lock must never be reported as taken while the server holding it is unreachable.',
            );

            $relay = $this->startRelay($port, $room, $upstream);
            usleep(300_000);

            self::assertFalse(
                $runtime->ready(),
                'An established client does not heal on its own; the replica stays drained until recycled.',
            );

            $replacement = $this->runtimeThrough($port, $upstream);
            self::assertTrue($replacement->ready(), 'A replacement connection must find the server back.');
            $counted = (new RedisAuthenticationRateLimiter($replacement));
            $counted->assertAllowed($subject, $origin);
            $counted->record($subject, $origin, true);
            $recovered = new CachedSiteSettings($this->siteSettings($container), $replacement, $logger);
            self::assertSame($warm, $recovered->current());

            // The other way a cache stops answering: an entry that is present and unreadable. A public
            // read must survive that too, and for the same reason — the table already has the answer.
            $poisoned = count($logger->warnings);
            $this->clientThrough($port, $upstream)->set('cache:site-settings', 'this is not a settings document');
            self::assertSame($warm, $recovered->current(), 'A damaged entry must not fail a public read.');
            self::assertGreaterThan($poisoned, count($logger->warnings), 'A damaged entry must be recorded.');
        } finally {
            if ($relay !== null) {
                posix_kill($relay['pid'], SIGKILL);
            }
            $this->cleanUp($room);
            $this->rollBackSettingsEntry($upstream);
        }
    }

    /**
     * Return the drill's one cache entry to absent on the server that outlives this process.
     *
     * The drill both warms and poisons `cache:site-settings` inside its own namespace, and the poison is
     * written without an expiry, so it would survive into the next suite run: the warm read there would
     * meet it, be answered from the database with a degradation notice for each read, and rightly fail
     * the assertion that a healthy cache records none. The class staged the residue, so the class removes
     * it — straight against the deployment's own server, because the relay it poisoned through is already
     * dead when this runs.
     *
     * @param   RedisConfiguration  $upstream  The deployment's Redis settings the drill namespaced under.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function rollBackSettingsEntry(RedisConfiguration $upstream): void
    {
        (new RedisRuntime($this->client($upstream, $upstream->host, $upstream->port)))
            ->forgetCache('site-settings');
    }

    /**
     * Read the deployment's Redis settings from the booted container rather than from raw process env.
     *
     * The suite's configuration arrives through `Environment::fromGlobals()`, which reads `.env` without
     * exporting it, so `getenv()` here can disagree with what the rest of the suite is using — and a
     * fallback namespace shared between installations is exactly how one run's poisoned entry reaches
     * another's healthy-cache assertion. The container's own configuration is the one truth.
     *
     * @param   Container  $container  Booted integration container.
     *
     * @return  RedisConfiguration  The deployment's Redis settings.
     *
     * @since   2.0.0
     */
    private function upstream(Container $container): RedisConfiguration
    {
        $configuration = $container->get(ApplicationConfiguration::class);
        if (!$configuration instanceof ApplicationConfiguration) {
            throw new RuntimeException('The integration application configuration is unavailable.');
        }

        return $configuration->redis;
    }

    /**
     * Try to take a lease and report the refusal, so the drill can insist an outage is never a success.
     */
    private function leaseFailure(RedisRuntime $runtime, string $name): ?object
    {
        try {
            return $runtime->acquireLease($name, 30);
        } catch (RuntimeException $failure) {
            self::assertSame('Redis is unreachable.', $failure->getMessage());

            return null;
        }
    }

    /**
     * Open a real client through the relay's port, using the deployment's own connection factory.
     *
     * @param   int                 $port      Local port the relay listens on.
     * @param   RedisConfiguration  $upstream  The deployment's Redis settings behind the relay.
     *
     * @return  RedisRuntime  Runtime whose connection dies with the relay.
     *
     * @since   2.0.0
     */
    private function runtimeThrough(int $port, RedisConfiguration $upstream): RedisRuntime
    {
        return new RedisRuntime($this->clientThrough($port, $upstream));
    }

    /**
     * Open the raw client, so the drill can put something in the keyspace the runtime cannot write.
     *
     * @param   int                 $port      Local port the relay listens on.
     * @param   RedisConfiguration  $upstream  The deployment's Redis settings behind the relay.
     *
     * @return  Redis  Connected client whose keys live in the drill's own namespace.
     *
     * @since   2.0.0
     */
    private function clientThrough(int $port, RedisConfiguration $upstream): Redis
    {
        return $this->client($upstream, '127.0.0.1', $port);
    }

    /**
     * Open one client against an explicit endpoint, inside the drill's own namespace.
     *
     * The namespace suffix keeps the drill's writes away from the keys the rest of the suite shares, and
     * deriving the stem from the deployment's configuration keeps two installations on one server from
     * meeting each other's residue.
     *
     * @param   RedisConfiguration  $upstream  The deployment's Redis settings supplying credentials.
     * @param   string              $host      Host to connect to: the relay's, or the server's own.
     * @param   int                 $port      Port to connect to.
     *
     * @return  Redis  Connected client whose keys live in the drill's own namespace.
     *
     * @since   2.0.0
     */
    private function client(RedisConfiguration $upstream, string $host, int $port): Redis
    {
        return (new RedisConnectionFactory(new RedisConfiguration(
            $host,
            $port,
            $upstream->password,
            $upstream->database,
            $upstream->namespace . '.outage-drill',
        )))->create();
    }

    /**
     * Rebuild the wired identity gateway around one drill limiter, leaving every other collaborator alone.
     */
    private function gatewayWith(
        Container $container,
        RedisAuthenticationRateLimiter $limiter,
    ): AdministratorIdentityGateway {
        $wired = $container->get(AdministratorIdentityGateway::class);
        if (!$wired instanceof DoctrineAdministratorIdentityGateway) {
            throw new RuntimeException('The integration identity gateway is unavailable.');
        }
        $constructor = (new ReflectionClass($wired))->getConstructor();
        if ($constructor === null) {
            throw new RuntimeException('The identity gateway cannot be rebuilt.');
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $parameter->getName() === 'rateLimiter'
                ? $limiter
                : (new ReflectionProperty($wired::class, $parameter->getName()))->getValue($wired);
        }

        return new DoctrineAdministratorIdentityGateway(...$arguments);
    }

    private function siteSettings(Container $container): DoctrineSiteSettings
    {
        $settings = $container->get(DoctrineSiteSettings::class);
        if (!$settings instanceof DoctrineSiteSettings) {
            throw new RuntimeException('The integration site settings are unavailable.');
        }

        return $settings;
    }

    /**
     * Start the relay process the drill's connections run through, and wait until it listens.
     *
     * @param   int                 $port      Local port the relay should listen on.
     * @param   string              $room      Scratch directory the relay reports into.
     * @param   RedisConfiguration  $upstream  The deployment's Redis settings the relay forwards to.
     *
     * @return  array{process: resource, pid: int}
     *
     * @since   2.0.0
     */
    private function startRelay(int $port, string $room, RedisConfiguration $upstream): array
    {
        $ready = $room . '/relay-ready-' . bin2hex(random_bytes(4));
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/Support/tcp-outage-relay.php',
                (string) $port,
                $upstream->host,
                (string) $upstream->port,
                $ready,
            ],
            [1 => ['file', $room . '/relay-stdout', 'a'], 2 => ['file', $room . '/relay-stderr', 'a']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $ready);
            if (is_file($ready)) {
                break;
            }
            usleep(25_000);
        }
        self::assertFileExists($ready, 'The Redis relay must be listening before the drill uses it.');
        $status = proc_get_status($process);
        self::assertIsInt($status['pid']);

        return ['process' => $process, 'pid' => $status['pid']];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        self::assertIsString($name);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function cleanUp(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

/**
 * A logger that keeps its warnings, so a degraded read can be shown to be visible rather than silent.
 *
 * @since  2.0.0
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * Warning messages recorded so far, oldest first.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $warnings = [];

    /**
     * Record one entry, keeping only the warnings this drill asserts on.
     *
     * @param   mixed                $level    PSR-3 level of the entry.
     * @param   string|Stringable    $message  Message as recorded.
     * @param   array<string, mixed> $context  Structured context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = (string) $message;
        }
    }
}
