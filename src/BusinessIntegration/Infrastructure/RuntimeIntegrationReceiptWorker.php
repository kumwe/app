<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Infrastructure;

use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Automation\RuntimeDeadline;
use Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\App\BusinessIntegration\Application\IntegrationReceiptWorker;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\Automation\QueueRuntimePolicyCatalog;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\Integration\ConsumerIdempotency;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\WebhookContributionDefinition;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Executes independently leased receipts from the current trusted host contribution graph.
 *
 * Replicas run this bounded single-effect entry point concurrently. A hung target occupies one lease
 * until its enforced deadline; another replica can claim a different consumer or scope immediately.
 *
 * @since  2.0.0
 */
final readonly class RuntimeIntegrationReceiptWorker implements IntegrationReceiptWorker
{
    /**
     * Wire host authority, durable claims and the existing consumer/outbound execution boundaries.
     *
     * @param  DoctrineInboxStore                  $inbox          Durable receipts and fair batch claims.
     * @param  ExtensionContributionRegistrySet    $contributions  Current executable registry.
     * @param  CanonicalEncoder                    $encoder        Package event reconstruction.
     * @param  IntegrationEventConsumerDispatcher  $consumers      Atomic internal effects and receipts.
     * @param  DurableOutboundAdapterDispatcher    $outbound       External effects and idempotency receipts.
     * @param  TrustedRuntimeGenerationGuard       $guard          Current trust and generation authority.
     * @param  SystemPrincipal                     $worker         Host-issued worker identity.
     * @param  QueueRuntimePolicyCatalog           $policies       Signed queue deadlines.
     * @param  LoggerInterface                     $logger         Per-receipt diagnostics.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DoctrineInboxStore $inbox,
        private ExtensionContributionRegistrySet $contributions,
        private CanonicalEncoder $encoder,
        private IntegrationEventConsumerDispatcher $consumers,
        private DurableOutboundAdapterDispatcher $outbound,
        private TrustedRuntimeGenerationGuard $guard,
        private SystemPrincipal $worker,
        private QueueRuntimePolicyCatalog $policies,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run one fair independent delivery with its own deadline and durable retry budget.
     *
     * @param   string  $workerId      Replica and process identity used in the lease.
     * @param   string  $generation    Current trusted generation.
     * @param   int     $leaseSeconds  Maximum claim lease before queue-policy narrowing.
     *
     * @return  bool  True when one receipt was attempted, including its independently recorded failure.
     *
     * @throws  RuntimeException  When the trusted registry contains a degraded executable entry.
     *
     * @since   2.0.0
     */
    public function dispatchOne(string $workerId, string $generation, int $leaseSeconds = 60): bool
    {
        $this->guard->assertCurrent($generation);
        $definitions = [];
        $handlers = [];
        $webhooks = [];
        foreach ($this->contributions->eventConsumers()->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $handler = $entry['implementation'];
            if (!$definition instanceof EventConsumerDefinition || !$handler instanceof IntegrationEventHandler) {
                throw new RuntimeException('The trusted consumer registry contains an invalid executable entry.');
            }
            $definitions[] = $definition;
            $handlers[$definition->identifier()] = $handler;
        }
        foreach ($this->contributions->webhooks()->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $adapter = $entry['implementation'];
            if (
                !$definition instanceof WebhookContributionDefinition
                || !$adapter instanceof IntegrationEventTransport
            ) {
                throw new RuntimeException('The trusted webhook registry contains an invalid executable entry.');
            }
            $webhooks[$definition->identifier()] = [$definition, $adapter];
            foreach ($definition->eventTypes() as $eventType) {
                $definitions[] = new EventConsumerDefinition(
                    $definition->identifier(),
                    $eventType,
                    $definition->schemaVersions(),
                    $definition->handlerVersion(),
                    $definition->queue(),
                    $definition->idempotency() === ConsumerIdempotency::AGGREGATE_VERSION,
                    $definition->idempotency(),
                    $definition->maximumAttempts(),
                    $definition->sensitivityCeiling(),
                );
            }
        }
        $leases = $this->inbox->claimBatch($definitions, $this->encoder, $workerId, $generation, $leaseSeconds);
        if ($leases === []) {
            return false;
        }
        $lease = $leases[0];
        $id = $lease->consumer->identifier();
        $policy = $this->policies->policy($lease->consumer->queue());
        $seconds = min($leaseSeconds, $policy->leaseSeconds ?? $leaseSeconds);
        try {
            $this->guard->assertCurrent($generation);
            (new RuntimeDeadline(max(1, intdiv($seconds * 4, 5)), 'The receipt effect exceeded its deadline.'))
                ->run(function () use ($lease, $id, $handlers, $webhooks): void {
                    if (isset($handlers[$id])) {
                        $context = $this->worker->context(
                            SiteContext::fromString($lease->event->siteIdentifier()),
                            'integration-' . $lease->event->eventId(),
                            $lease->event->correlationId(),
                        );
                        $this->consumers->consumeClaimed($lease, $handlers[$id], $context);
                    } else {
                        [$definition, $adapter] = $webhooks[$id];
                        $this->outbound->dispatchClaimed($definition, $adapter, $lease);
                    }
                });
        } catch (Throwable $failure) {
            // Dispatchers record retry/poison outcomes. If the fence or trust was lost before execution,
            // expiry safely returns the unsettled receipt to the next current worker.
            $this->logger->warning('Independent integration receipt attempt failed.', [
                'consumer_id' => $id, 'event_id' => $lease->event->eventId(), 'exception' => $failure,
            ]);
        }
        return true;
    }
}
