<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\QueueWorkerPermitsMigration;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\Automation\JitterSource;
use Kumwe\Automation\PermanentFailure;
use Kumwe\Automation\RetryPolicy;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\InboxClaimResult;
use Kumwe\Integration\InboxDisposition;
use Kumwe\Integration\InboxStore;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\Integration\EventSchemaDefinition;
use Kumwe\Integration\RecordedIntegrationEvent;
use Kumwe\App\BusinessIntegration\Infrastructure\RuntimeIntegrationEventTransport;
use Kumwe\App\BusinessReporting\Application\ProjectionRebuildResult;
use Kumwe\App\BusinessReporting\Application\ProjectionRuntime;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\Integration\ConsumerIdempotency;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\EventSensitivity;
use Kumwe\Integration\WebhookContributionDefinition;
use Kumwe\Integration\IntegrationEvent;
use Kumwe\Contribution\ContributionDefinition;
use Kumwe\Contribution\ContributionOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use RuntimeException;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;

#[CoversClass(RuntimeIntegrationEventTransport::class)]
/**
 * Proves runtime fan-out refuses a consumer registry entry whose executable contract degraded.
 *
 * @since  2.0.0
 */
final class RuntimeIntegrationEventTransportTest extends TestCase
{
    /**
     * Prove an executable entry without a consumer contract fails delivery permanently.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConsumerEntryWithoutItsContractFailsPermanently(): void
    {
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
            withCore: false,
        );
        $registries->eventConsumers()->register(
            ContributionOwner::extension('acme/probe'),
            self::degradedDefinition(),
            self::integrationEventHandler(),
        );
        $transport = new RuntimeIntegrationEventTransport(
            $registries,
            self::uncalled(DoctrineInboxStore::class),
            self::projections(),
            new RuntimeMaterializationState('replica-1', 7, '', '', true),
            self::createStub(TrustedRuntimeGenerationGuard::class),
        );

        $this->expectException(PermanentFailure::class);
        $this->expectExceptionMessage('invalid executable entry');

        $transport->publish(self::event());
    }

    /**
     * Prove an active consumer whose event type matches receives the delivery through its inbox.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnActiveConsumerReceivesTheDeliveryThroughItsDurableInbox(): void
    {
        $event = self::event();
        $definition = new EventConsumerDefinition(
            'acme.probe.observe-later',
            'acme.probe.observed',
            [1],
            '1.0.0',
        );
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
            withCore: false,
        );
        $registries->eventConsumers()->register(
            ContributionOwner::extension('acme/probe'),
            $definition,
            self::integrationEventHandler(),
        );
        $contracts = new EventContractRegistry(new DeterministicCanonicalEncoder(), [new EventSchemaDefinition(
            new DeterministicCanonicalEncoder(),
            'acme.probe.observed',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        )], [$definition]);
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'fanout_');
        (new CoreSchemaMigration($tables))->up($database);
        (new BusinessIntegrationSdkMigration($tables))->up($database);
        (new QueueWorkerPermitsMigration($tables))->up($database);
        $inbox = new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            self::clock(),
            $contracts,
        );
        $transport = new RuntimeIntegrationEventTransport(
            $registries,
            $inbox,
            self::projections(),
            new RuntimeMaterializationState('replica-1', 7, '', '', true),
            self::createStub(TrustedRuntimeGenerationGuard::class),
        );

        $transport->publish($event);
        $transport->publish($event);
        $rows = $inbox->recent($definition->identifier());
        self::assertCount(1, $rows);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame(0, (int) $rows[0]['attempts']);

        self::assertSame('core.runtime-fanout', $transport->identifier());
        self::assertSame(EventSensitivity::SECRET, $transport->sensitivityCeiling());
    }

    /**
     * Prove a declared outbound adapter gets its own durable receipt carrying the adapter's signed terms.
     *
     * Publication must not call the adapter. It records a pending receipt whose queue, handler revision and
     * attempt budget come from the webhook declaration, claimable under the consumer contract derived from
     * it, and an adapter that does not declare the event type gets no receipt at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeclaredOutboundAdapterReceivesAnIndependentReceiptWithItsSignedTerms(): void
    {
        $event = self::event();
        $declared = new WebhookContributionDefinition(
            'acme.probe.search-push',
            ['acme.probe.observed'],
            [1],
            '2.1.0',
            'integration.webhooks',
            ConsumerIdempotency::AGGREGATE_VERSION,
            4,
        );
        $unrelated = new WebhookContributionDefinition(
            'acme.probe.audit-push',
            ['acme.probe.deleted'],
            [1],
            '1.0.0',
            'integration.webhooks',
        );
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
            withCore: false,
        );
        $adapter = $this->createMock(IntegrationEventTransport::class);
        $adapter->expects(self::never())->method('publish');
        $registries->webhooks()->register(ContributionOwner::extension('acme/probe'), $declared, $adapter);
        $registries->webhooks()->register(ContributionOwner::extension('acme/probe'), $unrelated, $adapter);
        $contracts = new EventContractRegistry(new DeterministicCanonicalEncoder(), [new EventSchemaDefinition(
            new DeterministicCanonicalEncoder(),
            'acme.probe.observed',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        )], []);
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'fanout_');
        (new CoreSchemaMigration($tables))->up($database);
        (new BusinessIntegrationSdkMigration($tables))->up($database);
        (new QueueWorkerPermitsMigration($tables))->up($database);
        $inbox = new DoctrineInboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            self::clock(),
            $contracts,
        );
        $transport = new RuntimeIntegrationEventTransport(
            $registries,
            $inbox,
            self::projections(),
            new RuntimeMaterializationState('replica-1', 7, '', '', true),
            self::createStub(TrustedRuntimeGenerationGuard::class),
        );

        $transport->publish($event);
        $transport->publish($event);

        $rows = $inbox->recent('acme.probe.search-push');
        self::assertCount(1, $rows);
        self::assertSame($event->eventId(), $rows[0]['event_id']);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame('integration.webhooks', $rows[0]['queue']);
        self::assertSame('2.1.0', $rows[0]['handler_version']);
        self::assertSame(4, (int) $rows[0]['maximum_attempts']);
        self::assertSame([], $inbox->recent('acme.probe.audit-push'));
        $receipt = $inbox->claimBatch(
            [new EventConsumerDefinition(
                'acme.probe.search-push',
                'acme.probe.observed',
                [1],
                '2.1.0',
                'integration.webhooks',
                true,
                ConsumerIdempotency::AGGREGATE_VERSION,
                4,
            )],
            new DeterministicCanonicalEncoder(),
            'replica-1-worker',
            '7',
            30,
        );
        self::assertCount(1, $receipt, 'The receipt is claimable under the adapter-derived consumer contract.');
    }

    /**
     * Build the probe integration event the fan-out delivers.
     *
     * @return  RecordedIntegrationEvent  Versioned probe event.
     *
     * @since   2.0.0
     */
    private static function event(): RecordedIntegrationEvent
    {
        return new RecordedIntegrationEvent(
            new DeterministicCanonicalEncoder(),
            'acme.probe.observed',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            null,
            'worker',
            'default',
            null,
            'acme.record',
            'record-1',
            1,
            'correlation-1',
            'request-1',
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-1'],
        );
    }

    /**
     * Build a fixed clock for the retry policy.
     *
     * @return  ClockInterface  Clock pinned to one instant.
     *
     * @since   2.0.0
     */
    private static function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            /**
             * Report the pinned probe instant.
             *
             * @return  DateTimeImmutable  Fixed timestamp.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-10T10:00:00+00:00');
            }
        };
    }

    /**
     * Build a deterministic jitter source for the retry policy.
     *
     * @return  JitterSource  Jitter that always answers the minimum.
     *
     * @since   2.0.0
     */
    private static function jitter(): JitterSource
    {
        return new class implements JitterSource {
            /**
             * Answer the minimum of the requested range.
             *
             * @param   int  $minimum  Lower bound.
             * @param   int  $maximum  Upper bound.
             *
             * @return  int  The lower bound.
             *
             * @since   2.0.0
             */
            public function between(int $minimum, int $maximum): int
            {
                unset($maximum);

                return $minimum;
            }
        };
    }

    /**
     * Build a declarative definition that is not an event-consumer contract.
     *
     * The generic runtime registry accepts any contribution definition, so a degraded composition
     * can pair a foreign declaration with an executable; fan-out has to catch it at delivery.
     *
     * @return  ContributionDefinition  Definition of the wrong contract type.
     *
     * @since   2.0.0
     */
    private static function degradedDefinition(): ContributionDefinition
    {
        return new class implements ContributionDefinition {
            /**
             * Report the owner-namespaced probe identifier.
             *
             * @return  string  Probe identifier.
             *
             * @since   2.0.0
             */
            public function identifier(): string
            {
                return 'acme.probe.degraded-consumer';
            }

            /**
             * Export the inert declaration document.
             *
             * @return  array<string, mixed>  Probe declaration.
             *
             * @since   2.0.0
             */
            public function toArray(): array
            {
                return ['id' => 'acme.probe.degraded-consumer'];
            }
        };
    }

    /**
     * Build an inert durable-consumer executable probe.
     *
     * @return  IntegrationEventHandler  Executable that records nothing.
     *
     * @since   2.0.0
     */
    private static function integrationEventHandler(): IntegrationEventHandler
    {
        return new class implements IntegrationEventHandler {
            /**
             * Accept one durable delivery without side effects.
             *
             * @param   EventConsumerDefinition  $definition  Declared consumer contract.
             * @param   IntegrationEvent         $event       Delivered integration event.
             * @param   ExecutionContext         $context     Host-issued execution capabilities.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function handle(
                EventConsumerDefinition $definition,
                IntegrationEvent $event,
                ExecutionContext $context,
            ): void {
                throw new RuntimeException('Publication must not execute a consumer.');
            }
        };
    }

    /**
     * Build a projection runtime that tolerates live application and refuses maintenance calls.
     *
     * @return  ProjectionRuntime  Runtime that applies silently.
     *
     * @since   2.0.0
     */
    private static function projections(): ProjectionRuntime
    {
        return new class implements ProjectionRuntime {
            /**
             * Accept one live event without deriving state.
             *
             * @param   IntegrationEvent  $event  Delivered integration event.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function apply(IntegrationEvent $event): void
            {
                unset($event);
            }

            /**
             * Refuse rebuilds; this probe only observes live fan-out.
             *
             * @param   string  $projectionId  Namespaced projection identifier.
             *
             * @return  ProjectionRebuildResult  Never returned.
             *
             * @since   2.0.0
             */
            public function rebuild(string $projectionId): ProjectionRebuildResult
            {
                unset($projectionId);

                throw new RuntimeException('The probe projection runtime never rebuilds.');
            }

            /**
             * Report an empty projection inventory.
             *
             * @return  array<string, mixed>  Empty inventory.
             *
             * @since   2.0.0
             */
            public function inventory(): array
            {
                return [];
            }
        };
    }

    /**
     * Materialize a collaborator that the refusal path must never invoke.
     *
     * @template T of object
     *
     * @param   class-string<T>  $class  Final collaborator class.
     *
     * @return  T  Structurally complete but unwired instance.
     *
     * @since   2.0.0
     */
    private static function uncalled(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
