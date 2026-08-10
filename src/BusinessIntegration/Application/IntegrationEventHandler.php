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
    /**
     * Return the signed contribution definition implemented by this handler.
     *
     * @return  EventConsumerDefinition  Data-only contract matched to this executable handler.
     *
     * @since   2.0.0
     */
    public function definition(): EventConsumerDefinition;

    /**
     * Process the supplied item under its authenticated execution context.
     *
     * @param   IntegrationEvent  $event    Versioned event being validated or processed.
     * @param   ExecutionContext  $context  Authenticated execution context for authorization and audit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(IntegrationEvent $event, ExecutionContext $context): void;
}
