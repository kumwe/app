<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Automation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Joomla\DI\Container;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueue;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Proves what a worker's lease is worth when the database goes away between the effect and its settlement.
 *
 * The HTTP side already had a kill-point test; the queue side did not, so the claim that a worker killed
 * after a handler succeeded but before `complete()` is harmless rested on reasoning alone. This closes
 * the connection at exactly that point — the same thing a database restart does to a worker that has no
 * in-process reconnect — and then plays out what the supervisor's replacement finds: the lease expires,
 * another worker takes the job over, and the original worker's settlement, if it ever arrives, is
 * refused by the fence rather than marking someone else's attempt complete.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineJobQueue::class)]
final class WorkerConnectionLossKillPointIntegrationTest extends TestCase
{
    public function testAWorkerKilledBetweenItsEffectAndItsSettlementLosesTheJobToTheFence(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $secondary = TestKernelFactory::create($environment);
        $context = TestKernelFactory::workerContext($primary);
        $successorContext = TestKernelFactory::workerContext($secondary);
        $clock = new MovableWorkerClock(new DateTimeImmutable('2026-08-14T13:00:00', new DateTimeZone('UTC')));
        $queue = 'kill-point-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $original = $this->queue($primary, $clock);
        $successor = $this->queue($secondary, $clock);

        $jobId = $original->enqueue(
            TestKernelFactory::administratorContext($primary),
            'system.sessions.purge',
            ['probe' => 'kill-point'],
            $clock->now(),
            $queue,
        );
        $claimed = $original->claim($context, $queue, 'worker-original', 30);
        self::assertNotNull($claimed);
        self::assertSame($jobId, $claimed->id);

        // The kill point: the handler has succeeded and its external effect has landed, but the
        // settlement has not been written. A database restart takes the connection with it here.
        $this->connection($primary)->close();

        $clock->advance(31);
        $reclaimed = $successor->claim($successorContext, $queue, 'worker-successor', 30);
        self::assertNotNull($reclaimed, 'The expired lease must let a replacement worker take the job over.');
        self::assertSame($jobId, $reclaimed->id);
        self::assertNotSame($claimed->leaseToken, $reclaimed->leaseToken);
        self::assertSame(2, $reclaimed->attempts);

        try {
            $original->complete($context, $claimed, 'worker-original');
            self::fail('A worker that lost its lease cannot settle the job the successor now owns.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('lease', strtolower($exception->getMessage()));
        }

        $successor->complete($successorContext, $reclaimed, 'worker-successor');
        self::assertSame('completed', $this->connection($secondary)->fetchOne(sprintf(
            'SELECT status FROM %s WHERE id = ?',
            $this->tables($secondary)->quoted('jobs'),
        ), [$jobId]));
    }

    private function queue(Container $container, ClockInterface $clock): DoctrineJobQueue
    {
        $connection = $this->connection($container);
        $authorization = $container->get(AuthorizationGateway::class);
        $ownership = $container->get(ResourceSiteOwnershipWriter::class);
        $scope = $container->get(JobExecutionScope::class);
        $policies = $container->get(QueueRuntimePolicyCatalog::class);
        if (
            !$authorization instanceof AuthorizationGateway
            || !$ownership instanceof ResourceSiteOwnershipWriter
            || !$scope instanceof JobExecutionScope
        ) {
            throw new RuntimeException('The automation collaborators are unavailable.');
        }

        return new DoctrineJobQueue(
            $connection,
            $this->tables($container),
            new DoctrineTransactionManager($connection),
            $clock,
            'kill-point-release',
            $authorization,
            $ownership,
            $scope,
            $policies instanceof QueueRuntimePolicyCatalog ? $policies : null,
        );
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
}

/**
 * A clock the test moves by hand so a worker lease expires exactly when the scenario says it does.
 *
 * @since  2.0.0
 */
final class MovableWorkerClock implements ClockInterface
{
    /**
     * Hold the instant every reader of this clock currently sees.
     *
     * @param  DateTimeImmutable  $instant  Current instant.
     *
     * @since  2.0.0
     */
    public function __construct(private DateTimeImmutable $instant)
    {
    }

    /**
     * Report the instant this clock currently stands at.
     *
     * @return  DateTimeImmutable  Current instant.
     *
     * @since   2.0.0
     */
    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    /**
     * Move the clock forward, which is how a lease is made to expire deterministically.
     *
     * @param   int  $seconds  Seconds to advance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function advance(int $seconds): void
    {
        $this->instant = $this->instant->modify(sprintf('+%d seconds', $seconds));
    }
}
