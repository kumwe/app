<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessIntegration;

use Kumwe\CMS\Tests\Support\TranslatesConsoleOutput;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\CMS\Application\Automation\RetryPolicy;
use Kumwe\CMS\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\CMS\BusinessIntegration\Application\OutboxDispatcher;
use Kumwe\CMS\BusinessIntegration\Application\ProcessWorkDispatcher;
use Kumwe\CMS\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\CMS\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\CMS\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\CMS\Delivery\Console\Command\IntegrationWorkCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves a hung outbound endpoint cannot pin an integration worker for the rest of its life.
 *
 * The job worker has always run its handlers under an alarm; the integration worker, which is the one
 * that actually talks to somebody else's endpoint, did not. This drill supplies the failure the gap
 * describes: a transport whose `publish()` never returns on its own, reached through the real command,
 * the real dispatcher and a real outbox row on the engine under test. Nothing in the test interrupts
 * the publish — the only thing that ends it is the deadline the command now arms, which is why the
 * elapsed-time assertion is the load-bearing one: without the deadline this test does not fail with a
 * wrong value, it never returns.
 *
 * @since  2.0.0
 */
#[CoversClass(IntegrationWorkCommand::class)]
#[CoversClass(OutboxDispatcher::class)]
#[CoversClass(DoctrineOutboxStore::class)]
final class HungEndpointDeadlineIntegrationTest extends TestCase
{
    /**
     * Wall-clock seconds the hung transport would block for if nothing ever interrupted it.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int ENDPOINT_HANG_SECONDS = 120;

    public function testAHungPublishBecomesARecordedFailedAttemptInsteadOfAPinnedWorker(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $connection = $this->outboxDatabase();
        $tables = new TableNames($connection, 'kumwe_');
        $contracts = new EventContractRegistry([$this->schema()], []);
        $clock = $container->get(ClockInterface::class);
        $retries = $container->get(RetryPolicy::class);
        $guard = $container->get(TrustedRuntimeGenerationGuard::class);
        $processes = $container->get(ProcessWorkDispatcher::class);
        $compiler = $container->get(ExtensionRuntimeMapCompiler::class);
        $loaded = $container->get(RuntimeMaterializationState::class);
        if (
            !$clock instanceof ClockInterface
            || !$retries instanceof RetryPolicy
            || !$guard instanceof TrustedRuntimeGenerationGuard
            || !$processes instanceof ProcessWorkDispatcher
            || !$compiler instanceof ExtensionRuntimeMapCompiler
            || !$loaded instanceof RuntimeMaterializationState
        ) {
            throw new RuntimeException('The integration worker collaborators are unavailable.');
        }

        $outbox = new DoctrineOutboxStore(
            $connection,
            $tables,
            new DoctrineTransactionManager($connection),
            $clock,
            $contracts,
        );
        $transport = new HungOutboundEndpoint(self::ENDPOINT_HANG_SECONDS);
        $command = new IntegrationWorkCommand(
            new OutboxDispatcher($outbox, $contracts, $transport, $retries, $guard, new NullLogger()),
            $processes,
            $compiler,
            $loaded,
        );
        $event = $this->event();
        $outbox->append($event, 5);

        $startedAt = hrtime(true);
        $status = $command->execute(
            ['--once', '--stream=outbox', '--lease-seconds=10'],
            new DiscardedOutput(),
        );
        $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertTrue($transport->entered, 'The drill must actually reach the hung publish.');
        self::assertLessThan(
            self::ENDPOINT_HANG_SECONDS / 2,
            $elapsed,
            'The worker returned only because the endpoint stopped hanging, which proves nothing.',
        );
        self::assertSame(0, $status, 'A cut effect is an ordinary failed attempt, not a worker crash.');

        $row = $connection->fetchAssociative(sprintf(
            'SELECT status, attempts, error_message, lease_owner FROM %s WHERE event_id = ?',
            $tables->quoted('integration_outbox'),
        ), [$event->eventId()]);
        self::assertIsArray($row);
        self::assertSame('pending', $row['status'], 'A cut effect must be left retryable, not buried.');
        self::assertSame(1, (int) $row['attempts']);
        self::assertNull($row['lease_owner'], 'The cut attempt must release the fence it held.');
        self::assertIsString($row['error_message']);
        self::assertStringContainsString('dispatch deadline', $row['error_message']);
    }

    private function schema(): EventSchemaDefinition
    {
        return new EventSchemaDefinition(
            'deadline.drill.raised',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['subject'],
                'properties' => ['subject' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        );
    }

    private function event(): IntegrationEvent
    {
        return new IntegrationEvent(
            'deadline.drill.raised',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-14T09:00:00+00:00'),
            null,
            'deadline-drill',
            'default',
            null,
            'deadline.drill',
            'subject-one',
            1,
            'correlation-deadline-drill',
            'request-deadline-drill',
            EventSensitivity::INTERNAL,
            ['subject' => 'subject-one'],
        );
    }

    /**
     * Build the drill its own outbox, so what the worker claims is the row this test wrote.
     *
     * The shared installation database is drained by whatever else the suite has appended, and the
     * dispatcher claims the oldest eligible row rather than a named one — so a drill pointed at it would
     * spend its one claim on somebody else's event and prove nothing about a hung endpoint. A private
     * schema keeps the subject of the drill unambiguous while leaving the store, the dispatcher, the
     * command and the alarm exactly as production wires them.
     */
    private function outboxDatabase(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($connection, 'kumwe_');
        (new CoreSchemaMigration($tables))->up($connection);
        (new BusinessIntegrationSdkMigration($tables))->up($connection);

        return $connection;
    }
}

/**
 * An outbound adapter that accepts the event and then behaves exactly like an endpoint that never answers.
 *
 * @since  2.0.0
 */
final class HungOutboundEndpoint implements IntegrationEventTransport
{
    /**
     * Whether the drill actually reached the publish, so a skipped effect cannot read as a cut one.
     *
     * @var    bool
     * @since  2.0.0
     */
    public bool $entered = false;

    /**
     * Fix how long this endpoint would block for if nothing interrupted it.
     *
     * @param  int  $hangSeconds  Seconds the publish blocks for on its own.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly int $hangSeconds)
    {
    }

    /**
     * Name this adapter for the dispatcher's log lines.
     *
     * @return  string  Stable adapter identifier.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return 'drill.hung-endpoint';
    }

    /**
     * Accept every sensitivity, so nothing but the hang decides the outcome.
     *
     * @return  EventSensitivity  Highest sensitivity this adapter accepts.
     *
     * @since   2.0.0
     */
    public function sensitivityCeiling(): EventSensitivity
    {
        return EventSensitivity::SECRET;
    }

    /**
     * Block the caller the way a connected endpoint that never answers does.
     *
     * @param   IntegrationEvent  $event  Event handed over for delivery.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function publish(IntegrationEvent $event): void
    {
        $this->entered = true;
        $deadline = microtime(true) + $this->hangSeconds;
        while (microtime(true) < $deadline) {
            sleep(1);
        }
    }
}

/**
 * Console output the drill discards, since what it asserts on is the stored row rather than the text.
 *
 * @since  2.0.0
 */
final class DiscardedOutput implements Output
{
    use TranslatesConsoleOutput;

    /**
     * Discard one ordinary line.
     *
     * @param   string  $message  Line the command emitted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function line(string $message): void
    {
    }

    /**
     * Discard one failure line.
     *
     * @param   string  $message  Failure the command emitted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void
    {
    }
}
