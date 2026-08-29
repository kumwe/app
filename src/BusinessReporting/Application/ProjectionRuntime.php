<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;

/**
 * Trusted runtime surface for live projection maintenance and operator rebuilds.
 *
 * @since  2.0.0
 */
interface ProjectionRuntime
{
    /**
     * Bring every active projection that consumes the event up to its durable source sequence.
     *
     * @param   IntegrationEvent  $event  Durable outbox event being dispatched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function apply(IntegrationEvent $event): void;

    /**
     * Rebuild one exact active contribution into a replacement generation and atomically activate it.
     *
     * @param   string  $projectionId  Namespaced active projection identifier.
     *
     * @return  ProjectionRebuildResult  Reproducibility evidence for the activated generation.
     *
     * @since   2.0.0
     */
    public function rebuild(string $projectionId): ProjectionRebuildResult;

    /**
     * Return active contribution and persisted generation evidence for authorized operators.
     *
     * @return  list<array<string, mixed>>  Projection inventory in identifier order.
     *
     * @since   2.0.0
     */
    public function inventory(): array;
}
