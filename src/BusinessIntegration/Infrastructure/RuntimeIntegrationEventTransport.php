<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Infrastructure;

use Kumwe\Automation\PermanentFailure;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventFanout;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\ConsumerIdempotency;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\Integration\EventSensitivity;
use Kumwe\Integration\IntegrationEvent;
use Kumwe\Integration\WebhookContributionDefinition;
use Kumwe\App\BusinessReporting\Application\ProjectionRuntime;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use RuntimeException;

/**
 * Materializes an independent durable receipt for every active consumer and outbound adapter.
 *
 * Publication runs no consumer or network effect. A short receipt transaction is all-or-nothing,
 * and replay preserves every existing receipt. Workers claim and settle targets independently.
 *
 * @since  2.0.0
 */
final readonly class RuntimeIntegrationEventTransport implements IntegrationEventFanout
{
    /**
     * Bind outbox publication to durable fanout materialization and projection sequencing.
     *
     * @param  ExtensionContributionRegistrySet  $contributions  Active trusted executable declarations.
     * @param  DoctrineInboxStore                $inbox          Host receipt materialization adapter.
     * @param  ProjectionRuntime                 $projections    Idempotent live projection application.
     * @param  RuntimeMaterializationState       $runtime        Immutable loaded generation.
     * @param  TrustedRuntimeGenerationGuard     $guard          Current trust and generation authority.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionContributionRegistrySet $contributions,
        private DoctrineInboxStore $inbox,
        private ProjectionRuntime $projections,
        private RuntimeMaterializationState $runtime,
        private TrustedRuntimeGenerationGuard $guard,
    ) {
    }

    /**
     * Return the stable identifier for the runtime integration event transport.
     *
     * @return  string  Stable identifier of the runtime fan-out transport.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return 'core.runtime-fanout';
    }

    /**
     * Return the highest event sensitivity this contribution may receive.
     *
     * @return  EventSensitivity  Maximum event sensitivity accepted by runtime fan-out.
     *
     * @since   2.0.0
     */
    public function sensitivityCeiling(): EventSensitivity
    {
        return EventSensitivity::SECRET;
    }

    /**
     * Publish the supplied event through this declared transport.
     *
     * @param   IntegrationEvent  $event  Versioned event being validated or processed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function publish(IntegrationEvent $event): void
    {
        if (!$this->runtime->trusted || $this->runtime->generation < 0) {
            throw new RuntimeException('Integration delivery requires a trusted runtime generation.');
        }
        $this->guard->assertCurrent((string) $this->runtime->generation);
        $receipts = [];
        foreach ($this->contributions->eventConsumers()->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $handler = $entry['implementation'];
            if (!$definition instanceof EventConsumerDefinition || !$handler instanceof IntegrationEventHandler) {
                throw new PermanentFailure('The trusted consumer registry contains an invalid executable entry.');
            }
            if ($definition->eventType() !== $event->eventType()) {
                continue;
            }
            $receipts[] = $definition;
        }

        foreach ($this->contributions->webhooks()->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $adapter = $entry['implementation'];
            if (
                !$definition instanceof WebhookContributionDefinition
                || !$adapter instanceof IntegrationEventTransport
            ) {
                throw new PermanentFailure('The trusted webhook registry contains an invalid executable entry.');
            }
            if (!in_array($event->eventType(), $definition->eventTypes(), true)) {
                continue;
            }
            $receipts[] = new EventConsumerDefinition(
                $definition->identifier(),
                $event->eventType(),
                $definition->schemaVersions(),
                $definition->handlerVersion(),
                $definition->queue(),
                $definition->idempotency() === ConsumerIdempotency::AGGREGATE_VERSION,
                $definition->idempotency(),
                $definition->maximumAttempts(),
                $definition->sensitivityCeiling(),
            );
        }
        $this->projections->apply($event);
        $this->guard->assertCurrent((string) $this->runtime->generation);
        $this->inbox->materialize($receipts, $event);
    }
}
