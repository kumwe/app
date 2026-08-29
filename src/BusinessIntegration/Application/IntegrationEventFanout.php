<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;

/**
 * Host-owned fan-out boundary that delivers a durable outbox event to active runtime targets.
 *
 * This port is distinct from the SDK webhook transport: one host fan-out dispatches projections,
 * consumers, and outbound adapters, while each SDK transport executes only its signed webhook
 * definition.
 *
 * @since  2.0.0
 */
interface IntegrationEventFanout
{
    /**
     * Return the stable identifier for the host integration fan-out.
     *
     * @return  string  Stable transport identity used in telemetry.
     *
     * @since   2.0.0
     */
    public function identifier(): string;

    /**
     * Return the highest event sensitivity the complete host fan-out may receive.
     *
     * @return  EventSensitivity  Most sensitive event the boundary accepts.
     *
     * @since   2.0.0
     */
    public function sensitivityCeiling(): EventSensitivity;

    /**
     * Publish the supplied event to the complete active runtime graph.
     *
     * @param   IntegrationEvent  $event  Contract-validated durable event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function publish(IntegrationEvent $event): void;
}
