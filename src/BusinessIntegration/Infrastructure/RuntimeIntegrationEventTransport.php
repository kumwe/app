<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Infrastructure;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Application\Automation\PermanentFailure;
use Kumwe\CMS\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\CMS\BusinessIntegration\Application\InboxDisposition;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\CMS\BusinessIntegration\Domain\WebhookContributionDefinition;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Runtime\RuntimeMaterializationState;
use RuntimeException;

/**
 * Deterministically fans an outbox fact into every active consumer and outbound adapter.
 *
 * Each target owns an independent inbox receipt. If a later target fails, targets already completed
 * are skipped on the next outbox attempt, preventing duplicate effects while preserving at-least-once
 * recovery for unavailable, reordered, or upgraded consumers.
 *
 * @since  2.0.0
 */
final readonly class RuntimeIntegrationEventTransport implements IntegrationEventTransport
{
    /** @since 2.0.0 */
    public function __construct(
        private ExtensionContributionRegistrySet $contributions,
        private IntegrationEventConsumerDispatcher $consumers,
        private DurableOutboundAdapterDispatcher $outbound,
        private SystemPrincipal $worker,
        private RuntimeMaterializationState $runtime,
    ) {
    }

    /** @inheritDoc */
    public function identifier(): string
    {
        return 'core.runtime-fanout';
    }

    /** @inheritDoc */
    public function sensitivityCeiling(): EventSensitivity
    {
        return EventSensitivity::SECRET;
    }

    /** @inheritDoc */
    public function publish(IntegrationEvent $event): void
    {
        if (!$this->runtime->trusted || $this->runtime->generation < 0) {
            throw new RuntimeException('Integration delivery requires a trusted runtime generation.');
        }
        $generation = (string) $this->runtime->generation;
        $workerId = $this->runtime->replicaId . ':integration';
        $context = $this->worker->context(
            SiteContext::fromString($event->siteIdentifier()),
            'integration-' . $event->eventId(),
            $event->correlationId(),
        );

        foreach ($this->contributions->eventConsumers()->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $handler = $entry['implementation'];
            if (!$definition instanceof EventConsumerDefinition || !$handler instanceof IntegrationEventHandler) {
                throw new PermanentFailure('The trusted consumer registry contains an invalid executable entry.');
            }
            if ($definition->eventType() !== $event->eventType()) {
                continue;
            }
            $disposition = $this->consumers->consume(
                $event,
                $handler,
                $context,
                $workerId,
                $generation,
            );
            if (!in_array($disposition, [InboxDisposition::CLAIMED, InboxDisposition::DUPLICATE], true)) {
                throw new RuntimeException('An active integration consumer is not currently claimable.');
            }
        }

        foreach ($this->contributions->webhooks()->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $adapter = $entry['implementation'];
            if (!$definition instanceof WebhookContributionDefinition || !$adapter instanceof IntegrationEventTransport) {
                throw new PermanentFailure('The trusted webhook registry contains an invalid executable entry.');
            }
            if (!in_array($event->eventType(), $definition->eventTypes(), true)) {
                continue;
            }
            $disposition = $this->outbound->dispatch(
                $definition,
                $adapter,
                $event,
                $workerId,
                $generation,
            );
            if (!in_array($disposition, [InboxDisposition::CLAIMED, InboxDisposition::DUPLICATE], true)) {
                throw new RuntimeException('An active outbound adapter does not support this event revision.');
            }
        }
    }
}
