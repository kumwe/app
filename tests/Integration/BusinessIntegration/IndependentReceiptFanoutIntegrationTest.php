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
use Kumwe\Automation\QueueRuntimePolicy;
use Kumwe\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\Integration\IntegrationEvent;
use Kumwe\Integration\WebhookContributionDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Psr\Log\NullLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Observability\CorrelationContext;
use Kumwe\App\Infrastructure\Persistence\Migration\AsyncTraceContextMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\JobRecoveryMigration;
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
use PHPUnit\Framework\Attributes\TestWith;
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
#[CoversClass(DurableOutboundAdapterDispatcher::class)]
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
            $store->complete($lease);
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
     * Prove an outage or enforced timeout rolls back its effect and preserves healthy consumer progress.
     *
     * @param   bool  $hang  Whether the first handler blocks until the real worker deadline interrupts it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testWorkerExecutesIndependentEffectsAndRetainsOnlyFailedReceiptForRetry(bool $hang): void
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
                static function () use ($database, $definition, $index, $hang): void {
                    $database->insert('receipt_effects', ['consumer_id' => $definition->identifier()]);
                    if ($index === 0) {
                        if ($hang) {
                            sleep(10);
                        }
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
        $started = microtime(true);
        for ($replica = 0; $replica < 3; $replica++) {
            self::assertTrue($worker->dispatchOne('replica-' . $replica, '7', 5));
        }
        self::assertFalse($worker->dispatchOne('replica-next', '7', 5));
        if ($hang) {
            self::assertLessThan(7.0, microtime(true) - $started, 'The worker must interrupt the ten-second hang.');
        }
        self::assertSame(['acme.probe.b', 'acme.probe.c'], $database->fetchFirstColumn(
            'SELECT consumer_id FROM receipt_effects ORDER BY consumer_id',
        ));
        self::assertSame('pending', $store->recent('acme.probe.a')[0]['status']);
        self::assertSame('completed', $store->recent('acme.probe.b')[0]['status']);
        self::assertSame('completed', $store->recent('acme.probe.c')[0]['status']);
    }

    /**
     * Prove a hung target and a consumer-wide outage cannot consume sibling capacity or new-traffic retries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConsumerConcurrencyAndCircuitBackoffAreSharedAcrossScopesAndReplicas(): void
    {
        $a = $this->consumer('acme.probe.a');
        $b = $this->consumer('acme.probe.b');
        [$store, $database, $tables, $clock, $contracts] = $this->store([$a, $b]);
        $store->materialize([$a], $this->event('site-a', 'org-a'));
        $store->materialize([$a], $this->event('site-b', 'org-b'));
        $store->materialize([$b], $this->event('site-a', 'org-a'));
        $leases = $store->claimBatch([$a, $b], new DeterministicCanonicalEncoder(), 'replica-one', '7', 30, 10);
        self::assertCount(2, $leases, 'One hung consumer has only one active receipt across all scopes.');
        self::assertSame('acme.probe.a', $leases[0]->consumer->identifier());
        self::assertSame('acme.probe.b', $leases[1]->consumer->identifier());
        $store->complete($leases[1]);
        $store->fail(
            $leases[0],
            FailureClassification::TRANSIENT,
            new RuntimeException('endpoint unavailable'),
            $clock->now()->modify('+30 seconds')
        );
        $store->materialize([$a], $this->event('site-c', 'org-c'));
        self::assertSame([], $store->claimBatch([$a, $b], new DeterministicCanonicalEncoder(), 'replica-two', '7', 30));
        $later = new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $this->clock($clock->now()->modify('+31 seconds')),
            $contracts
        );
        $probe = $later->claimBatch([$a, $b], new DeterministicCanonicalEncoder(), 'replica-probe', '7', 30, 10);
        self::assertCount(1, $probe, 'Only one half-open probe is admitted after a downstream outage.');
        $later->complete($probe[0]);
        self::assertCount(1, $later->claimBatch(
            [$a, $b],
            new DeterministicCanonicalEncoder(),
            'replica-recovery',
            '7',
            30,
        ));
    }

    /**
     * Prove a signed handler upgrade restarts poison attempts but cannot steal an old active receipt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGenerationUpgradePreservesActiveFencesAndRestartsOnlyPoisonWork(): void
    {
        $a = $this->consumer('acme.probe.a');
        [$store, $database, $tables, $clock, $contracts] = $this->store([$a]);
        $store->materialize([$a], $this->event('default', null));
        $old = $store->claimBatch([$a], new DeterministicCanonicalEncoder(), 'replica-old', '7', 30)[0];
        $upgraded = new EventConsumerDefinition(
            $a->identifier(),
            $a->eventType(),
            [1],
            '2.0.0',
            $a->queue(),
            false
        );
        self::assertSame([], $store->claimBatch(
            [$upgraded],
            new DeterministicCanonicalEncoder(),
            'replica-new',
            '8',
            30,
        ));
        $store->fail($old, FailureClassification::PERMANENT, new RuntimeException('old handler poison'), null);
        $replacement = $store->claimBatch([$upgraded], new DeterministicCanonicalEncoder(), 'replica-new', '8', 30);
        self::assertCount(1, $replacement);
        self::assertSame(1, $replacement[0]->attempts);
        self::assertSame('2.0.0', $replacement[0]->consumer->handlerVersion());
        $store->complete($replacement[0]);
        $store->materialize([$upgraded], $old->event);
        self::assertSame([], $store->claimBatch(
            [$upgraded],
            new DeterministicCanonicalEncoder(),
            'replica-new',
            '8',
            30,
        ));
    }

    /**
     * Preserve recovery when a signed compatibility declaration expands without a binary version change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnavailableReceiptRechecksCompatibilityWithoutSpendingAttempts(): void
    {
        $unsupported = new EventConsumerDefinition('acme.probe.a', 'acme.changed', [2], '1.0.0');
        [$store, $database, $tables, $clock, $contracts] = $this->store([$unsupported], [1, 2]);
        $store->materialize([$unsupported], $this->event('default', null));
        self::assertSame([], $store->claimBatch([$unsupported], new DeterministicCanonicalEncoder(), 'one', '7', 30));
        $receipt = $store->recent($unsupported->identifier())[0];
        self::assertSame('unavailable', $receipt['status']);
        self::assertSame(0, (int) $receipt['attempts']);
        $compatible = new EventConsumerDefinition('acme.probe.a', 'acme.changed', [1, 2], '1.0.0');
        self::assertSame([], $store->claimBatch([$compatible], new DeterministicCanonicalEncoder(), 'two', '8', 30));
        $later = new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $this->clock($clock->now()->modify('+61 seconds')),
            $contracts,
        );
        $leases = $later->claimBatch([$compatible], new DeterministicCanonicalEncoder(), 'two', '8', 30);
        self::assertCount(1, $leases);
        self::assertSame(1, $leases[0]->attempts);
        $later->complete($leases[0]);
        self::assertSame('completed', $later->recent($compatible->identifier())[0]['status']);
    }

    /**
     * Prove revocation during an internal effect rolls back that effect before receipt settlement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTrustRevokedDuringEffectPreventsCommitAndRetainsReceipt(): void
    {
        $definition = $this->consumer('acme.probe.a');
        [$store, $database, $tables, $clock, $contracts] = $this->store([$definition]);
        $store->materialize([$definition], $this->event('default', null));
        $lease = $store->claimBatch([$definition], new DeterministicCanonicalEncoder(), 'worker', '7', 30)[0];
        $database->executeStatement('CREATE TABLE receipt_effects (consumer_id VARCHAR(191) PRIMARY KEY)');
        $revoked = false;
        $guard = self::createStub(TrustedRuntimeGenerationGuard::class);
        $guard->method('assertCurrent')->willReturnCallback(static function () use (&$revoked): void {
            if ($revoked) {
                throw new RuntimeException('The runtime trust was revoked.');
            }
        });
        $handler = self::createMock(IntegrationEventHandler::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            static function () use ($database, $definition, &$revoked): void {
                $database->insert('receipt_effects', ['consumer_id' => $definition->identifier()]);
                $revoked = true;
            },
        );
        $jitter = self::createStub(JitterSource::class);
        $jitter->method('between')->willReturn(1);
        $dispatcher = new IntegrationEventConsumerDispatcher(
            $store,
            $contracts,
            new RetryPolicy($clock, $jitter),
            $guard,
            new DoctrineTransactionManager($database),
            new NullLogger(),
        );
        $context = SystemPrincipal::issue(new \stdClass(), SystemIdentity::Worker)->context(
            \Kumwe\Context\Value\SiteContext::fromString('default'),
            'receipt-revocation',
            'correlation',
        );
        try {
            $dispatcher->consumeClaimed($lease, $handler, $context);
            self::fail('A revoked consumer settled its receipt.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('revoked', $failure->getMessage());
        }
        self::assertSame([], $database->fetchFirstColumn('SELECT consumer_id FROM receipt_effects'));
        self::assertSame('pending', $store->recent($definition->identifier())[0]['status']);
        self::assertSame(1, (int) $store->recent($definition->identifier())[0]['attempts']);
    }

    /**
     * Prove scanning time cannot shorten consumer capacity and a lost consumer fence prevents renewal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConsumerPermitUsesExactReceiptExpiryAndMustSurviveBeforeExecution(): void
    {
        $definition = $this->consumer('acme.probe.a');
        [$store, $database, $tables, $clock, $contracts] = $this->store([$definition]);
        $store->materialize([$definition], $this->event('default', null));
        $tick = 0;
        $advancing = self::createStub(ClockInterface::class);
        $advancing->method('now')->willReturnCallback(static function () use ($clock, &$tick): DateTimeImmutable {
            return $clock->now()->modify(sprintf('+%d seconds', $tick++));
        });
        $pool = new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $advancing,
            $contracts
        );
        $lease = $pool->claimBatch([$definition], new DeterministicCanonicalEncoder(), 'worker', '7', 30)[0];
        $expiry = $database->fetchOne('SELECT lease_expires_at FROM receipt_integration_inbox');
        self::assertSame(
            $expiry,
            $database->fetchOne('SELECT lease_expires_at FROM receipt_integration_delivery_health'),
        );
        $database->update(
            $tables->raw('integration_delivery_health'),
            ['lease_token' => Uuid::uuid7()->toString()],
            ['consumer_id' => $definition->identifier()]
        );
        try {
            $pool->renew($lease, 30, requireConsumerPermit: true);
            self::fail('A pooled worker renewed after losing its consumer execution permit.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('consumer execution permit', $failure->getMessage());
        }
        self::assertSame($expiry, $database->fetchOne('SELECT lease_expires_at FROM receipt_integration_inbox'));
    }

    /**
     * Prove a batch claim refuses an out-of-range bound and answers an empty active graph with no work.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBatchClaimRejectsUnboundedBatchesAndAnEmptyGraphClaimsNothing(): void
    {
        $a = $this->consumer('acme.probe.a');
        [$store] = $this->store([$a]);
        $store->materialize([$a], $this->event('default', null));
        foreach ([0, 65] as $limit) {
            try {
                $store->claimBatch([$a], new DeterministicCanonicalEncoder(), 'worker', '7', 30, $limit);
                self::fail(sprintf('A batch of %d leases was accepted.', $limit));
            } catch (\InvalidArgumentException $failure) {
                self::assertStringContainsString('between one and 64', $failure->getMessage());
            }
        }
        self::assertSame([], $store->claimBatch([], new DeterministicCanonicalEncoder(), 'worker', '7', 30, 64));
        self::assertSame('pending', $store->recent($a->identifier())[0]['status']);
        self::assertCount(1, $store->claimBatch([$a], new DeterministicCanonicalEncoder(), 'worker', '7', 30, 64));
    }

    /**
     * Prove a signed queue's in-flight permit bounds batch claims and follows the lease through settlement.
     *
     * With one permit, the second consumer in the batch is deferred as busy without spending an attempt.
     * Renewal moves the permit expiry with the receipt, and a failed settlement returns the permit so the
     * deferred receipt becomes claimable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testQueuePermitBoundsBatchClaimsAndIsRenewedAndReleasedWithTheReceipt(): void
    {
        $a = $this->consumer('acme.probe.a');
        $b = $this->consumer('acme.probe.b');
        $policies = self::createStub(QueueRuntimePolicyCatalog::class);
        $policies->method('policy')->willReturn(new QueueRuntimePolicy('integration.default', 30, 5, 1, 7, 7));
        [$store, $database, $tables, $clock, $contracts] = $this->store([$a, $b], [1], $policies);
        $store->materialize([$a, $b], $this->event('default', null));
        $permit = static fn (): array|false => $database->fetchAssociative(sprintf(
            'SELECT lease_token, lease_expires_at FROM %s WHERE queue_id = ?',
            $tables->quoted('job_queue_permits'),
        ), ['integration.default']);

        $leases = $store->claimBatch([$a, $b], new DeterministicCanonicalEncoder(), 'replica-one', '7', 30, 10);
        self::assertCount(1, $leases, 'One in-flight permit admits one receipt per batch.');
        self::assertSame('acme.probe.a', $leases[0]->consumer->identifier());
        $deferred = $store->recent('acme.probe.b')[0];
        self::assertSame('pending', $deferred['status']);
        self::assertSame(0, (int) $deferred['attempts']);
        $instant = static fn (mixed $stored): int => (new DateTimeImmutable(
            is_string($stored) ? $stored : '@0',
            new \DateTimeZone('UTC'),
        ))->getTimestamp();
        self::assertSame($clock->now()->getTimestamp() + 1, $instant($deferred['available_at']));
        self::assertSame($leases[0]->leaseToken, $permit()['lease_token'] ?? null);

        $store->renew($leases[0], 20);
        self::assertSame($clock->now()->getTimestamp() + 20, $instant($permit()['lease_expires_at'] ?? null));
        try {
            $store->renew($leases[0], 31);
            self::fail('A renewal widened the signed queue lease.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('signed policy', $failure->getMessage());
        }

        $store->fail($leases[0], FailureClassification::PERMANENT, new RuntimeException('rejected'), null);
        self::assertNull($permit()['lease_token'] ?? null, 'Settlement returns the in-flight permit.');
        self::assertSame('poison', $store->recent('acme.probe.a')[0]['status']);
        $later = new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $this->clock($clock->now()->modify('+2 seconds')),
            $contracts,
            $policies,
        );
        $next = $later->claimBatch([$a, $b], new DeterministicCanonicalEncoder(), 'replica-two', '7', 30, 10);
        self::assertCount(1, $next);
        self::assertSame('acme.probe.b', $next[0]->consumer->identifier());
        self::assertSame(1, $next[0]->attempts);
    }

    /**
     * Prove the worker executes a declared outbound adapter's receipt through the durable adapter dispatcher.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWorkerDeliversAnOutboundAdapterReceiptAndSettlesIt(): void
    {
        $webhook = new WebhookContributionDefinition(
            'acme.probe.push',
            ['acme.changed'],
            [1],
            '1.0.0',
            'integration.default',
        );
        $receipt = new EventConsumerDefinition(
            'acme.probe.push',
            'acme.changed',
            [1],
            '1.0.0',
            'integration.default',
            false,
        );
        [$store, $database, $tables, $clock, $contracts] = $this->store([]);
        $event = $this->event('default', null);
        $store->materialize([$receipt], $event);
        $encoder = new DeterministicCanonicalEncoder();
        $registry = new ExtensionContributionRegistrySet(
            $encoder,
            new SdkFieldConfigurationAdmission(),
            withCore: false,
        );
        $adapter = $this->createMock(IntegrationEventTransport::class);
        $adapter->expects(self::once())->method('publish')->with(
            $webhook,
            self::callback(
                static fn (IntegrationEvent $delivered): bool => $delivered->eventId() === $event->eventId(),
            ),
        );
        $registry->webhooks()->register(ContributionOwner::extension('acme/probe'), $webhook, $adapter);
        $guard = self::createStub(TrustedRuntimeGenerationGuard::class);
        $retries = new RetryPolicy($clock, self::createStub(JitterSource::class));
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

        self::assertTrue($worker->dispatchOne('replica-0', '7', 5));
        self::assertFalse($worker->dispatchOne('replica-1', '7', 5));
        $settled = $store->recent('acme.probe.push')[0];
        self::assertSame('completed', $settled['status']);
        self::assertSame(1, (int) $settled['attempts']);
    }

    /**
     * A consumer runs inside an `inbox` frame carrying the event's identifiers and the trace its receipt recorded.
     *
     * The receipt is materialized while a dispatch frame carrying an upstream trace is open, exactly as the
     * runtime fan-out does, so the trace crosses the outbox-to-inbox boundary on the durable row and the
     * consumer's lines join the originating request without any in-memory hand-off.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConsumerRunsInsideAFrameCarryingTheEventIdentifiersAndTheRecordedTrace(): void
    {
        $definition = $this->consumer('acme.probe.trace');
        [, $database, $tables, $clock, $contracts] = $this->store([$definition]);
        $correlation = new CorrelationContext();
        $store = new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $clock,
            $contracts,
            correlation: $correlation,
        );
        $event = $this->event('default', null);
        $correlation->enter(
            'outbox',
            'outbox-dispatch-' . $event->eventId(),
            'correlation',
            'cause',
            '0af7651916cd43dd8448eb211c80319c',
        );
        $store->materialize([$definition], $event);
        $correlation->leave('outbox');
        $seen = [];
        $handler = $this->createMock(IntegrationEventHandler::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            static function () use ($correlation, &$seen): void {
                $seen = $correlation->fragment();
            },
        );
        $encoder = new DeterministicCanonicalEncoder();
        $registry = new ExtensionContributionRegistrySet(
            $encoder,
            new SdkFieldConfigurationAdmission(),
            withCore: false,
        );
        $registry->eventConsumers()->register(ContributionOwner::extension('acme/probe'), $definition, $handler);
        $guard = self::createStub(TrustedRuntimeGenerationGuard::class);
        $retries = new RetryPolicy($clock, self::createStub(JitterSource::class));
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
                new NullLogger(),
            ),
            new DurableOutboundAdapterDispatcher($store, $contracts, $retries, $guard, new NullLogger()),
            $guard,
            SystemPrincipal::issue(new \stdClass(), SystemIdentity::Worker),
            self::createStub(QueueRuntimePolicyCatalog::class),
            new NullLogger(),
            $correlation,
        );

        self::assertTrue($worker->dispatchOne('replica-0', '7', 5));

        self::assertSame('integration-' . $event->eventId(), $seen['request_id'] ?? null);
        self::assertSame('correlation', $seen['correlation_id'] ?? null);
        self::assertSame('cause', $seen['causation_id'] ?? null);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $seen['trace_id'] ?? null);
        self::assertSame('inbox.consume', $seen['operation'] ?? null);
        self::assertSame('acme.probe.trace', $seen['consumer_id'] ?? null);
        self::assertSame($event->eventId(), $seen['event_id'] ?? null);
        self::assertFalse($correlation->inside('inbox'), 'The attempt must close its frame.');
        self::assertSame('completed', $store->recent('acme.probe.trace')[0]['status']);
    }

    /**
     * Build a schema/consumer catalog and isolated durable database.
     *
     * @param   list<EventConsumerDefinition>  $consumers  Trusted graph under test.
     * @param   list<int>                      $revisions  Available signed schema revisions.
     * @param   ?QueueRuntimePolicyCatalog     $policies   Signed queue limits, or null for core defaults.
     *
     * @return  array{DoctrineInboxStore, Connection, TableNames, ClockInterface, EventContractRegistry}  Fixture.
     *
     * @since   2.0.0
     */
    private function store(
        array $consumers,
        array $revisions = [1],
        ?QueueRuntimePolicyCatalog $policies = null,
    ): array {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'receipt_');
        (new CoreSchemaMigration($tables))->up($database);
        (new JobRecoveryMigration($tables))->up($database);
        (new BusinessIntegrationSdkMigration($tables))->up($database);
        (new QueueWorkerPermitsMigration($tables))->up($database);
        (new AsyncTraceContextMigration($tables))->up($database);
        $encoder = new DeterministicCanonicalEncoder();
        $schemas = array_map(static fn (int $revision): EventSchemaDefinition => new EventSchemaDefinition(
            $encoder,
            'acme.changed',
            $revision,
            EventSensitivity::INTERNAL,
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        ), $revisions);
        $contracts = new EventContractRegistry($encoder, $schemas, $consumers);
        $clock = $this->clock(new DateTimeImmutable('2026-09-24T10:00:00+00:00'));
        $store = new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $clock,
            $contracts,
            $policies,
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
