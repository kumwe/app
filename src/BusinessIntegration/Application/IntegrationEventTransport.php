<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use Kumwe\CMS\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;

/**
 * Delivery adapter receiving an outbox event under explicit sensitivity limits.
 *
 * @since  2.0.0
 */
interface IntegrationEventTransport
{
    /** @return string Stable transport identity used in telemetry. @since 2.0.0 */
    public function identifier(): string;

    /** @return EventSensitivity Most sensitive event the boundary accepts. @since 2.0.0 */
    public function sensitivityCeiling(): EventSensitivity;

    /** @return void @since 2.0.0 */
    public function publish(IntegrationEvent $event): void;
}
