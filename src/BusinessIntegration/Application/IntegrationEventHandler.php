<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;

/**
 * Idempotent durable consumer implementation for one declared consumer identity.
 *
 * @since  2.0.0
 */
interface IntegrationEventHandler
{
    /** @return EventConsumerDefinition Data-only contract matched to this executable handler. @since 2.0.0 */
    public function definition(): EventConsumerDefinition;

    /** @return void @since 2.0.0 */
    public function handle(IntegrationEvent $event, ExecutionContext $context): void;
}
