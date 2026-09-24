<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessIntegration;

use DateTimeImmutable;
use Kumwe\App\BusinessIntegration\Infrastructure\RuntimeIntegrationReceiptWorker;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\Contribution\ContributionOwner;
use Kumwe\Automation\RetryPolicy;
use Kumwe\Automation\JitterSource;
use Kumwe\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Psr\Log\NullLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\QueueWorkerPermitsMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\Automation\FailureClassification;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\EventSchemaDefinition;
use Kumwe\Integration\EventSensitivity;
use Kumwe\Integration\RecordedIntegrationEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves independent materialization, tenant/consumer turns, retry isolation and crash redelivery.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineInboxStore::class)]
#[CoversClass(RuntimeIntegrationReceiptWorker::class)]
#[CoversClass(IntegrationEventConsumerDispatcher::class)]
#[CoversClass(QueueWorkerPermitsMigration::class)]
final class IndependentReceiptFanoutIntegrationTest extends TestCase
{
    /**
     * Prove three and ten targets materialize once, can be batch-claimed, and settle independently.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFanoutThreeAndTenPreservesEveryReceiptAcrossReplayAndFailure(): void
    {
        foreach ([3, 10] as $count) {
            $definitions = [];
            for ($index = 0; $index < $count; $index++) {
                $definitions[] = $this->consumer('acme.consumer-' . $index);
            }
            [$store, $database, $tables, $clock] = $this->store($definitions);
            $event = $this->event('default', 'org-a');
            $store->materialize($definitions, $event);
            $store->materialize($definitions, $event);
            $leases = $store->claimBatch($definitions, new DeterministicCanonicalEncoder(), 'replica-one', '7', 30, 10);
            self::assertCount($count, $leases);
            $store->fail(
                $leases[0],
                FailureClassification::TRANSIENT,
                new RuntimeException('rate limited'),
                $clock->now()->modify('+5 minutes')
            );
            foreach (array_slice($leases, 1) as $lease) {
                $store->complete($lease);
            }
            $store->materialize($definitions, $event);
            self::assertSame([], $store->claimBatch(
                $definitions,
                new DeterministicCanonicalEncoder(),
                'replica-two',
                '7',
                30,
                10,
            ));
            self::assertSame($count, (int) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('integration_inbox'),
            )));
            self::assertSame('pending', $store->recent($definitions[0]->identifier())[0]['status']);
        }
    }

    /**
     * Prove a busy consumer/site/organization backlog cannot starve a later unrelated lane.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFairTurnsIncludeConsumerSiteAndOrganizationWhileNewTrafficContinues(): void
    {
        $a = $this->consumer('acme.probe.a');
        $b = $this->consumer('acme.probe.b');
        [$store] = $this->store([$a, $b]);
        for ($index = 0; $index < 20; $index++) {
            $store->materialize([$a], $this->event('site-a', 'org-a'));
        }
        $store->materialize([$a], $this->event('site-a', 'org-b'));
        $store->materialize([$a], $this->event('site-b', 'org-a'));
        $store->materialize([$b], $this->event('site-a', 'org-a'));
        $seen = [];
        for ($index = 0; $index < 4; $index++) {
            $leases = $store->claimBatch([$a, $b], new DeterministicCanonicalEncoder(), 'replica-' . $index, '7', 30);
            self::assertCount(1, $leases);
            $lease = $leases[0];
            $seen[] = $lease->consumer->identifier() . ':' . $lease->event->siteIdentifier()
                . ':' . $lease->event->organizationId();
            $store->materialize([$a], $this->event('site-a', 'org-a'));
            // Leave each lease live, simulating workers that have not returned yet.
        }
        sort($seen);
        self::assertSame(['acme.probe.a:site-a:org-a', 'acme.probe.a:site-a:org-b', 'acme.probe.a:site-b:org-a',
            'acme.probe.b:site-a:org-a'], $seen);
    }

    /**
     * Prove malformed payload quarantine and crash recovery do not prevent unrelated receipt claims.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPoisonDoesNotBlockHealthyWorkAndExpiredClaimRejectsStaleSettlement(): void
    {
        $a = $this->consumer('acme.probe.a');
        $b = $this->consumer('acme.probe.b');
        [$store, $database, $tables, $clock, $contracts] = $this->store([$a, $b]);
        $event = $this->event('default', null);
        $store->materialize([$a, $b], $event);
        $database->update($tables->raw('integration_inbox'), ['envelope' => '{}'], [
            'consumer_id' => $a->identifier(), 'event_id' => $event->eventId(),
        ]);
        $leases = $store->claimBatch([$a, $b], new DeterministicCanonicalEncoder(), 'replica-one', '7', 5, 10);
        self::assertCount(1, $leases);
        self::assertSame('acme.probe.b', $leases[0]->consumer->identifier());
        self::assertSame('poison', $store->recent('acme.probe.a')[0]['status']);
        $later = $this->clock($clock->now()->modify('+6 seconds'));
        $successor = new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $later,
            $contracts
        );
        $replacement = $successor->claimBatch([$a, $b], new DeterministicCanonicalEncoder(), 'replica-two', '8', 30);
        self::assertCount(1, $replacement);
        self::assertSame(2, $replacement[0]->attempts);
        self::assertNotSame($leases[0]->leaseToken, $replacement[0]->leaseToken);
        try {
            $store->complete($leases[0]);
            self::fail('A stale replica settled its successor receipt.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('lease', $failure->getMessage());
        }
        $successor->complete($replacement[0]);
        self::assertSame('completed', $store->recent('acme.probe.b')[0]['status']);
    }

    /**
     * Prove a bad fanout declaration rolls back earlier receipt inserts in the same publication.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMaterializationIsAtomic(): void
    {
        $a = $this->consumer('acme.probe.a');
        [$store] = $this->store([$a]);
        try {
            $store->materialize(
                [$a, new EventConsumerDefinition('acme.probe.bad', 'other.changed', [1], '1.0.0')],
                $this->event('default', null)
            );
            self::fail('A mismatched fanout declaration was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $store->recent('acme.probe.a'));
        }
    }

    /**
     * Prove an outage rolls back its local effect and does not stall either healthy consumer worker.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWorkerExecutesIndependentEffectsAndRetainsOnlyFailedReceiptForRetry(): void
    {
        $definitions = [
            $this->consumer('acme.probe.a'), $this->consumer('acme.probe.b'), $this->consumer('acme.probe.c'),
        ];
        [$store, $database, $tables, $clock, $contracts] = $this->store($definitions);
        $encoder = new DeterministicCanonicalEncoder();
        $admission = new SdkFieldConfigurationAdmission();
        $registry = new ExtensionContributionRegistrySet($encoder, $admission, withCore: false);
        $database->executeStatement('CREATE TABLE receipt_effects (consumer_id VARCHAR(191) PRIMARY KEY)');
        foreach ($definitions as $index => $definition) {
            $handler = $this->createMock(IntegrationEventHandler::class);
            $handler->expects(self::once())->method('handle')->willReturnCallback(
                static function () use ($database, $definition, $index): void {
                    $database->insert('receipt_effects', ['consumer_id' => $definition->identifier()]);
                    if ($index === 0) {
                        throw new RuntimeException('downstream outage');
                    }
                },
            );
            $registry->eventConsumers()->register(ContributionOwner::extension('acme/probe'), $definition, $handler);
        }
        $guard = self::createStub(TrustedRuntimeGenerationGuard::class);
        $jitter = self::createStub(JitterSource::class);
        $jitter->method('between')->willReturn(1);
        $retries = new RetryPolicy($clock, $jitter);
        $worker = new RuntimeIntegrationReceiptWorker(
            $store,
            $registry,
            $encoder,
            new IntegrationEventConsumerDispatcher(
                $store,
                $contracts,
                $retries,
                $guard,
                new DoctrineTransactionManager($database),
                new NullLogger()
            ),
            new DurableOutboundAdapterDispatcher($store, $contracts, $retries, $guard, new NullLogger()),
            $guard,
            SystemPrincipal::issue(new \stdClass(), SystemIdentity::Worker),
            self::createStub(QueueRuntimePolicyCatalog::class),
            new NullLogger(),
        );
        $store->materialize($definitions, $this->event('default', null));
        for ($replica = 0; $replica < 3; $replica++) {
            self::assertTrue($worker->dispatchOne('replica-' . $replica, '7', 30));
        }
        self::assertFalse($worker->dispatchOne('replica-next', '7', 30));
        self::assertSame(['acme.probe.b', 'acme.probe.c'], $database->fetchFirstColumn(
            'SELECT consumer_id FROM receipt_effects ORDER BY consumer_id',
        ));
        self::assertSame('pending', $store->recent('acme.probe.a')[0]['status']);
        self::assertSame('completed', $store->recent('acme.probe.b')[0]['status']);
        self::assertSame('completed', $store->recent('acme.probe.c')[0]['status']);
    }

    /**
     * Build a schema/consumer catalog and isolated durable database.
     *
     * @param   list<EventConsumerDefinition>  $consumers  Trusted graph under test.
     *
     * @return  array{DoctrineInboxStore, Connection, TableNames, ClockInterface, EventContractRegistry}  Fixture.
     *
     * @since   2.0.0
     */
    private function store(array $consumers): array
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'receipt_');
        (new CoreSchemaMigration($tables))->up($database);
        (new BusinessIntegrationSdkMigration($tables))->up($database);
        (new QueueWorkerPermitsMigration($tables))->up($database);
        $encoder = new DeterministicCanonicalEncoder();
        $contracts = new EventContractRegistry($encoder, [new EventSchemaDefinition(
            $encoder,
            'acme.changed',
            1,
            EventSensitivity::INTERNAL,
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]
        )], $consumers);
        $clock = $this->clock(new DateTimeImmutable('2026-09-24T10:00:00+00:00'));
        $store = new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $clock,
            $contracts,
        );
        return [$store, $database, $tables, $clock, $contracts];
    }

    /**
     * Declare an unordered idempotent target.
     *
     * @param   string  $id  Consumer identity.
     *
     * @return  EventConsumerDefinition  Trusted target.
     *
     * @since   2.0.0
     */
    private function consumer(string $id): EventConsumerDefinition
    {
        return new EventConsumerDefinition($id, 'acme.changed', [1], '1.0.0', 'integration.default', false);
    }

    /**
     * Build a valid event with explicit tenant identity.
     *
     * @param   string   $site  Owning site.
     * @param   ?string  $org   Organization scope.
     *
     * @return  RecordedIntegrationEvent  Package-validated event.
     *
     * @since   2.0.0
     */
    private function event(string $site, ?string $org): RecordedIntegrationEvent
    {
        return new RecordedIntegrationEvent(
            new DeterministicCanonicalEncoder(),
            'acme.changed',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-09-24T10:00:00+00:00'),
            null,
            'worker',
            $site,
            $org,
            'acme.record',
            Uuid::uuid7()->toString(),
            1,
            'correlation',
            'cause',
            EventSensitivity::INTERNAL,
            ['id' => 'record']
        );
    }

    /**
     * Supply a deterministic lease clock.
     *
     * @param   DateTimeImmutable  $now  Fixed comparison instant.
     *
     * @return  ClockInterface  Clock for an independent worker replica.
     *
     * @since   2.0.0
     */
    private function clock(DateTimeImmutable $now): ClockInterface
    {
        return new class ($now) implements ClockInterface {
            /**
             * Capture the worker's test instant.
             *
             * @param  DateTimeImmutable  $instant  Lease clock.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly DateTimeImmutable $instant)
            {
            }

            /**
             * Return the worker's test instant.
             *
             * @return  DateTimeImmutable  Lease comparison time.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return $this->instant;
            }
        };
    }
}
