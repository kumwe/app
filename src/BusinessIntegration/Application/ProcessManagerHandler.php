<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessInstance;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessTransition;

/**
 * Pure decision handler for one generic process-manager type.
 *
 * @since  2.0.0
 */
interface ProcessManagerHandler
{
    /** @return string Namespaced process type. @since 2.0.0 */
    public function processType(): string;

    /** @return string Correlation key selecting one process instance. @since 2.0.0 */
    public function correlationId(IntegrationEvent $event): string;

    /** @return ProcessTransition Initial state and requested durable effects. @since 2.0.0 */
    public function start(IntegrationEvent $event): ProcessTransition;

    /** @return ProcessTransition Next state and requested durable effects. @since 2.0.0 */
    public function apply(ProcessInstance $process, IntegrationEvent $event): ProcessTransition;
}
