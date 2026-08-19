<?php

declare(strict_types=1);

namespace KumweContract\ManifestFour\Integration;

use Kumwe\App\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\App\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\App\BusinessIntegration\Domain\IntegrationEvent;

/**
 * Outbound adapter half of the manifest-4 compatibility package.
 *
 * The transport publishes nowhere. It exists so the webhook surface is exercised by a real
 * implementation of the public transport contract, including the sensitivity ceiling that decides what
 * an adapter is ever offered.
 *
 * @since  2.0.0
 */
final readonly class ObservationWebhookTransport implements IntegrationEventTransport
{
    /**
     * Bind the transport to the evidence sink the fixture reads back.
     *
     * @param  ObservationLedger  $ledger  Process-local evidence sink.
     *
     * @since  2.0.0
     */
    public function __construct(private ObservationLedger $ledger)
    {
    }

    /**
     * Return the adapter identifier the manifest declares.
     *
     * @return  string  The package-namespaced outbound adapter identifier.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return 'kumwe.contract-manifest-four.observed-webhook';
    }

    /**
     * Record that delivery was attempted, without leaving the process.
     *
     * @param   IntegrationEvent  $event  Event offered for outbound delivery.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function publish(IntegrationEvent $event): void
    {
        $this->ledger->record('webhook');
    }

    /**
     * Report the highest sensitivity this adapter may ever be offered.
     *
     * @return  EventSensitivity  Public, which is the only class this fixture handles.
     *
     * @since   2.0.0
     */
    public function sensitivityCeiling(): EventSensitivity
    {
        return EventSensitivity::PUBLIC;
    }
}
