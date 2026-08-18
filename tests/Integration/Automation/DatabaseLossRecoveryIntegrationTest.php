<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Automation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Joomla\DI\Container;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\Infrastructure\Automation\DoctrineJobQueue;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Takes the database away from a live worker mid-job and proves what the platform actually does about it.
 *
 * The stated posture for database loss is crash to exit, be restarted by the supervisor, and let the
 * fenced lease decide who owns the work. Nothing tested it, so the claim rested on the absence of a
 * retry loop rather than on observed behaviour — and, as the second drill here shows, the absence of a
 * retry loop was not the whole story.
 *
 * Both drills sever the connection somewhere the process under test cannot influence. The first puts a
 * killable relay process on the network path and `SIGKILL`s it, which is what a stopped database looks
 * like from a client: the established connection is reset *and reconnecting is refused*. The second
 * asks the server to terminate the session — `KILL` on MySQL and MariaDB, `pg_terminate_backend()` on
 * PostgreSQL — while the server itself stays up, which is what a failover or an idle-session reaper
 * does. They produce different behaviour, and the difference is the point: only the first is a crash.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineJobQueue::class)]
final class DatabaseLossRecoveryIntegrationTest extends TestCase
{
    /**
     * Lease the worker holds when its database goes away under it.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int LEASE_SECONDS = 5;

    /**
     * Proves the crash-only contract end to end when the database is gone rather than merely disturbed.
     *
     * The worker reaches its settlement with no path to the database at all, so nothing it can do
     * recovers it: it dies, and dies without having recorded anything. What the drill then asserts is
     * that this leaves a *recoverable* state rather than a lost job — the lease still standing, no
     * invented failure record, and the replacement process the supervisor would start finishing the
     * work after the lease expires.
     *
     * The handler's own effect runs twice across the two processes, which is the platform's documented
     * at-least-once delivery and exactly what the idempotency records exist to absorb; the completion,
     * which is the thing the fence protects, happens once.
     */
    public function testADatabaseThatIsGoneCrashesTheWorkerAndTheRestartFinishesTheJobOnce(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $this->requireServerEngine($container);
        $queueName = 'database-gone-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $victimRoom = $this->room('database-gone-victim');
        $successorRoom = $this->room('database-gone-successor');
        $relayPort = $this->freePort();
        $relay = null;

        try {
            $jobId = $this->enqueue($container, $queueName, 'database-gone');
            $relay = $this->startRelay($relayPort, $victimRoom);

            $victim = $this->spawnWorker($queueName, $victimRoom, $relayPort);
            self::assertTrue(
                $this->await($victimRoom . '/handler-entered', 90.0),
                'The worker must be holding the job before its database goes away.',
            );
            $before = $this->jobRow($container, $jobId);
            self::assertSame('reserved', $before['status']);
            self::assertSame('killable-worker', $before['lease_owner']);

            // The database is now unreachable for that process, and stays unreachable: the reset is
            // permanent until the path is restored, so no reconnect can paper over it.
            $goneAt = microtime(true);
            self::assertTrue(posix_kill($relay['pid'], SIGKILL));
            $relay = null;
            file_put_contents($victimRoom . '/resume', 'go');

            $exit = $this->awaitExit($victim['process'], 90.0);
            self::assertNotNull($exit, 'A worker with no database must not keep running.');
            self::assertNotSame(0, $exit, 'A database that is gone must end the process, not be absorbed.');
            self::assertFileDoesNotExist(
                $victimRoom . '/worker-returned',
                'The worker must die inside its settlement rather than finish its pass.',
            );

            $stranded = $this->jobRow($container, $jobId);
            self::assertSame('reserved', $stranded['status'], 'A settlement that never landed must not appear to.');
            self::assertSame(1, (int) $stranded['attempts']);
            self::assertSame($before['lease_token'], $stranded['lease_token'], 'The dead fence must be untouched.');
            self::assertSame(0, $this->failedJobRows($container, $jobId), 'No attempt record may be invented.');

            // The supervisor's replacement: the same worker, started again once the path is back.
            $relay = $this->startRelay($relayPort, $successorRoom);
            file_put_contents($successorRoom . '/resume', 'go');
            $this->sleepUntil($goneAt + self::LEASE_SECONDS + 1.5);
            $successor = $this->spawnWorker($queueName, $successorRoom, $relayPort);
            self::assertSame(0, $this->awaitExit($successor['process'], 90.0), 'The restarted worker must drain.');

            $after = $this->jobRow($container, $jobId);
            self::assertSame('completed', $after['status'], 'The restarted worker must finish the stranded job.');
            self::assertSame(2, (int) $after['attempts'], 'Recovery is the second attempt, not a third.');
            self::assertNull($after['lease_owner']);
            self::assertNotSame($before['lease_token'], $after['lease_token']);
        } finally {
            if ($relay !== null) {
                posix_kill($relay['pid'], SIGKILL);
            }
            $this->cleanUp($victimRoom);
            $this->cleanUp($successorRoom);
        }
    }

    /**
     * Records what a severed session — as opposed to a vanished server — actually does to a worker.
     *
     * This is the drill that corrected an assumption. The platform ships no reconnect wrapper, so the
     * expectation was a crash here too; what happens instead is that DBAL converts the driver error into
     * `ConnectionLost`, closes the connection, and the next statement opens a new one. The worker's own
     * failure path therefore succeeds on a fresh session and the process drains cleanly.
     *
     * That is a better outcome than a crash and it costs nothing in integrity, which is what this asserts
     * rather than assumes: the attempt is recorded under the fence the worker still held, the job is left
     * retryable rather than completed by a settlement that never reached the server, and the effect is
     * not counted twice inside one pass.
     */
    public function testASeveredSessionIsRecoveredInPlaceWithoutInventingASettlement(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $platform = $this->requireServerEngine($container);
        $queueName = 'database-severed-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $room = $this->room('database-severed');

        try {
            $jobId = $this->enqueue($container, $queueName, 'database-severed');
            $worker = $this->spawnWorker($queueName, $room, null);
            self::assertTrue(
                $this->await($room . '/handler-entered', 90.0),
                'The worker must be holding the job before its session is severed.',
            );

            $severed = $this->severEverySessionButThisOne($this->connection($container), $platform);
            self::assertGreaterThan(0, $severed, 'The drill must actually terminate the worker session.');
            file_put_contents($room . '/resume', 'go');

            $exit = $this->awaitExit($worker['process'], 90.0);
            self::assertSame(0, $exit, 'A session the driver can replace must not take the process down.');

            $row = $this->jobRow($container, $jobId);
            self::assertSame(
                'pending',
                $row['status'],
                'A severed session must leave the job retryable, never completed and never held.',
            );
            self::assertSame(1, (int) $row['attempts'], 'One pass must never spend two attempts.');
            self::assertNull($row['lease_owner'], 'A released job must carry no fence.');
            self::assertSame(0, $this->failedJobRows($container, $jobId), 'A retryable attempt is not a burial.');
        } finally {
            $this->cleanUp($room);
        }
    }

    /**
     * Refuse to pretend a serverless engine can lose a connection.
     */
    private function requireServerEngine(Container $container): object
    {
        $platform = $this->connection($container)->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof PostgreSQLPlatform) {
            self::markTestSkipped(
                'Connection loss is only a real event on a client-server engine; this run has no server to sever.',
            );
        }

        return $platform;
    }

    /**
     * Ask the server to terminate every session in this database except the one asking.
     *
     * @return  int  Number of sessions the server was asked to end.
     */
    private function severEverySessionButThisOne(Connection $connection, object $platform): int
    {
        if ($platform instanceof PostgreSQLPlatform) {
            return count($connection->fetchFirstColumn(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity '
                . 'WHERE datname = current_database() AND pid <> pg_backend_pid()',
            ));
        }

        $sessions = $connection->fetchFirstColumn(
            'SELECT ID FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND ID <> CONNECTION_ID()',
        );
        foreach ($sessions as $session) {
            $connection->executeStatement(sprintf('KILL %d', (int) $session));
        }

        return count($sessions);
    }

    private function enqueue(Container $container, string $queueName, string $drill): string
    {
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $this->queue($container)->enqueue(
            TestKernelFactory::administratorContext($container),
            'system.sessions.purge',
            ['drill' => $drill],
            $clock->now(),
            $queueName,
        );
    }

    /**
     * Put a killable relay on the network path to the database and wait until it is listening.
     *
     * @return  array{process: resource, pid: int}
     */
    private function startRelay(int $port, string $room): array
    {
        $ready = $room . '/relay-ready-' . bin2hex(random_bytes(4));
        $descriptors = [
            1 => ['file', $room . '/relay-stdout', 'a'],
            2 => ['file', $room . '/relay-stderr', 'a'],
        ];
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/Support/tcp-outage-relay.php',
                (string) $port,
                (string) (getenv('DB_HOST') ?: '127.0.0.1'),
                (string) (getenv('DB_PORT') ?: '3306'),
                $ready,
            ],
            $descriptors,
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        self::assertTrue($this->await($ready, 20.0), 'The database relay must come up before the worker starts.');
        $status = proc_get_status($process);
        self::assertIsInt($status['pid']);

        return ['process' => $process, 'pid' => $status['pid']];
    }

    /**
     * @return  array{process: resource, pid: int}
     */
    private function spawnWorker(string $queueName, string $room, ?int $relayPort): array
    {
        $environment = getenv();
        if ($relayPort !== null) {
            $environment['DB_HOST'] = '127.0.0.1';
            $environment['DB_PORT'] = (string) $relayPort;
        }
        $descriptors = [
            1 => ['file', $room . '/worker-stdout', 'w'],
            2 => ['file', $room . '/worker-stderr', 'w'],
        ];
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/Support/killable-worker.php',
                $queueName,
                $room,
                (string) self::LEASE_SECONDS,
            ],
            $descriptors,
            $pipes,
            dirname(__DIR__, 3),
            $environment,
        );
        self::assertIsResource($process);
        $status = proc_get_status($process);
        self::assertIsInt($status['pid']);

        return ['process' => $process, 'pid' => $status['pid']];
    }

    /**
     * @param  resource  $process  Handle of a spawned process.
     */
    private function awaitExit($process, float $seconds): ?int
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if ($status['running'] === false) {
                return $status['exitcode'];
            }
            usleep(50_000);
        }

        return null;
    }

    private function await(string $path, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return true;
            }
            usleep(25_000);
        }

        return false;
    }

    private function sleepUntil(float $instant): void
    {
        $remaining = $instant - microtime(true);
        if ($remaining > 0) {
            usleep((int) ($remaining * 1_000_000));
        }
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        self::assertIsString($name);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        self::assertGreaterThan(0, $port);

        return $port;
    }

    private function room(string $name): string
    {
        $room = sys_get_temp_dir() . '/kumwe-' . $name . '-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($room, 0o700, true));

        return $room;
    }

    /** @return array<string, mixed> */
    private function jobRow(Container $container, string $jobId): array
    {
        $row = $this->connection($container)->fetchAssociative(sprintf(
            'SELECT status, attempts, lease_owner, lease_token FROM %s WHERE id = ?',
            $this->tables($container)->quoted('jobs'),
        ), [$jobId]);
        self::assertIsArray($row);

        return $row;
    }

    private function failedJobRows(Container $container, string $jobId): int
    {
        return (int) $this->connection($container)->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE job_id = ?',
            $this->tables($container)->quoted('failed_jobs'),
        ), [$jobId]);
    }

    private function queue(Container $container): JobQueue
    {
        $queue = $container->get(JobQueue::class);
        if (!$queue instanceof JobQueue) {
            throw new RuntimeException('The integration job queue is unavailable.');
        }

        return $queue;
    }

    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }

    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
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
