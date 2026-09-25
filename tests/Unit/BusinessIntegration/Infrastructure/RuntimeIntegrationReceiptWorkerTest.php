<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\BusinessIntegration\Infrastructure\RuntimeIntegrationReceiptWorker;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\Automation\JitterSource;
use Kumwe\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Automation\RetryPolicy;
use Kumwe\Contribution\ContributionDefinition;
use Kumwe\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\IntegrationEvent;
use Kumwe\Integration\WebhookContributionDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins that the independent receipt worker refuses a degraded trusted registry before it claims anything.
 *
 * The contribution registries type-check the implementation they hold but not the definition beside it, so
 * the worker is the last place a consumer or webhook entry whose declaration is not the contract it claims
 * can be stopped. The inbox here is an empty database: reaching the claim would fail on a missing table, so
 * the worker's own refusal message proves it stopped first.
 *
 * @since  2.0.0
 */
#[CoversClass(RuntimeIntegrationReceiptWorker::class)]
final class RuntimeIntegrationReceiptWorkerTest extends TestCase
{
    /**
     * A current worker with nothing trusted to deliver reports that it attempted nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEmptyTrustedRegistryAttemptsNothing(): void
    {
        $registries = self::registries();

        self::assertFalse($this->worker($registries)->dispatchOne('worker-a', 'generation-1'));
    }

    /**
     * An event-consumer entry whose declaration is not a consumer definition is refused before any claim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConsumerEntryWithAForeignDeclarationIsRefusedBeforeClaiming(): void
    {
        $registries = self::registries();
        $registries->eventConsumers()->register(
            ContributionOwner::extension('acme/probe'),
            self::foreignDefinition('acme.probe.degraded-consumer'),
            self::handler(),
        );

        self::assertSame(
            'The trusted consumer registry contains an invalid executable entry.',
            self::refusal(fn () => $this->worker($registries)->dispatchOne('worker-a', 'generation-1')),
        );
    }

    /**
     * A webhook entry whose declaration is not a webhook definition is refused before any claim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAWebhookEntryWithAForeignDeclarationIsRefusedBeforeClaiming(): void
    {
        $registries = self::registries();
        $registries->webhooks()->register(
            ContributionOwner::extension('acme/probe'),
            self::foreignDefinition('acme.probe.degraded-webhook'),
            self::transport(),
        );

        self::assertSame(
            'The trusted webhook registry contains an invalid executable entry.',
            self::refusal(fn () => $this->worker($registries)->dispatchOne('worker-a', 'generation-1')),
        );
    }

    /**
     * A generation the guard no longer trusts is refused before the registry is even read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleGenerationIsRefusedBeforeTheRegistryIsRead(): void
    {
        $registries = self::registries();
        $registries->eventConsumers()->register(
            ContributionOwner::extension('acme/probe'),
            self::foreignDefinition('acme.probe.degraded-consumer'),
            self::handler(),
        );
        $guard = self::createStub(TrustedRuntimeGenerationGuard::class);
        $guard->method('assertCurrent')->willThrowException(new RuntimeException('stale generation'));

        self::assertSame(
            'stale generation',
            self::refusal(fn () => $this->worker($registries, $guard)->dispatchOne('worker-a', 'generation-0')),
        );
    }

    /**
     * Run a dispatch that must be refused and return the refusal message.
     *
     * @param   callable(): bool  $dispatch  Dispatch attempt.
     *
     * @return  string  Message of the runtime refusal.
     *
     * @since   2.0.0
     */
    private static function refusal(callable $dispatch): string
    {
        try {
            $dispatch();
        } catch (RuntimeException $refusal) {
            return $refusal->getMessage();
        }
        self::fail('The dispatch must be refused.');
    }

    /**
     * Build the worker over an empty database so any claim would fail loudly.
     *
     * @param   ExtensionContributionRegistrySet    $registries  Trusted registries the worker reads.
     * @param   ?TrustedRuntimeGenerationGuard      $guard       Generation guard, current when omitted.
     *
     * @return  RuntimeIntegrationReceiptWorker  Worker under test.
     *
     * @since   2.0.0
     */
    private function worker(
        ExtensionContributionRegistrySet $registries,
        ?TrustedRuntimeGenerationGuard $guard = null,
    ): RuntimeIntegrationReceiptWorker {
        $database = self::database();
        $encoder = new DeterministicCanonicalEncoder();
        $contracts = new EventContractRegistry($encoder, [], []);
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-24T12:00:00+00:00'));
        $inbox = new DoctrineInboxStore(
            $database,
            new TableNames($database, 'kumwe_'),
            new DoctrineTransactionManager($database),
            $clock,
            $contracts,
        );
        $guard ??= self::createStub(TrustedRuntimeGenerationGuard::class);
        $retries = new RetryPolicy($clock, self::createStub(JitterSource::class));

        return new RuntimeIntegrationReceiptWorker(
            $inbox,
            $registries,
            $encoder,
            new IntegrationEventConsumerDispatcher(
                $inbox,
                $contracts,
                $retries,
                $guard,
                new DoctrineTransactionManager($database),
                new NullLogger(),
            ),
            new DurableOutboundAdapterDispatcher($inbox, $contracts, $retries, $guard, new NullLogger()),
            $guard,
            SystemPrincipal::issue(new \stdClass(), SystemIdentity::Worker),
            self::createStub(QueueRuntimePolicyCatalog::class),
            new NullLogger(),
        );
    }

    /**
     * Open an empty in-memory database with no inbox table.
     *
     * @return  Connection  Connection whose first inbox query would fail.
     *
     * @since   2.0.0
     */
    private static function database(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /**
     * Build trusted registries holding no core contributions.
     *
     * @return  ExtensionContributionRegistrySet  Empty registries.
     *
     * @since   2.0.0
     */
    private static function registries(): ExtensionContributionRegistrySet
    {
        return new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
            withCore: false,
        );
    }

    /**
     * Build a declaration that satisfies the registry but is not a consumer or webhook contract.
     *
     * @param   string  $identifier  Owner-namespaced probe identifier.
     *
     * @return  ContributionDefinition  Foreign declaration.
     *
     * @since   2.0.0
     */
    private static function foreignDefinition(string $identifier): ContributionDefinition
    {
        return new readonly class ($identifier) implements ContributionDefinition {
            /**
             * Keep the probe identifier.
             *
             * @param   string  $identifier  Owner-namespaced probe identifier.
             *
             * @since   2.0.0
             */
            public function __construct(private string $identifier)
            {
            }

            /**
             * Report the owner-namespaced probe identifier.
             *
             * @return  string  Probe identifier.
             *
             * @since   2.0.0
             */
            public function identifier(): string
            {
                return $this->identifier;
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
                return ['id' => $this->identifier];
            }
        };
    }

    /**
     * Build a consumer implementation that must never run.
     *
     * @return  IntegrationEventHandler  Refusing handler.
     *
     * @since   2.0.0
     */
    private static function handler(): IntegrationEventHandler
    {
        return new class implements IntegrationEventHandler {
            /**
             * Fail the test if a degraded entry is ever executed.
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
                throw new RuntimeException('A degraded consumer entry must not execute.');
            }
        };
    }

    /**
     * Build a webhook adapter that must never run.
     *
     * @return  IntegrationEventTransport  Refusing adapter.
     *
     * @since   2.0.0
     */
    private static function transport(): IntegrationEventTransport
    {
        return new class implements IntegrationEventTransport {
            /**
             * Fail the test if a degraded entry is ever executed.
             *
             * @param   WebhookContributionDefinition  $definition  Declared webhook contract.
             * @param   IntegrationEvent               $event       Delivered integration event.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function publish(WebhookContributionDefinition $definition, IntegrationEvent $event): void
            {
                throw new RuntimeException('A degraded webhook entry must not execute.');
            }
        };
    }
}
