<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\BusinessIntegration\Domain\ProcessInstance;
use Kumwe\App\BusinessIntegration\Domain\ProcessTransition;

/**
 * Pure decision handler for one generic process-manager type.
 *
 * @since  2.0.0
 */
interface ProcessManagerHandler
{
    /**
     * Return the stable process-manager type.
     *
     * @return  string  Namespaced process type.
     *
     * @since   2.0.0
     */
    public function processType(): string;

    /**
     * Derive the process correlation identifier from the event.
     *
     * @param   IntegrationEvent  $event  Versioned event being validated or processed.
     *
     * @return  string  Correlation key selecting one process instance.
     *
     * @since   2.0.0
     */
    public function correlationId(IntegrationEvent $event): string;

    /**
     * Create the initial transition for the supplied event.
     *
     * @param   IntegrationEvent  $event  Versioned event being validated or processed.
     *
     * @return  ProcessTransition  Initial state and requested durable effects.
     *
     * @since   2.0.0
     */
    public function start(IntegrationEvent $event): ProcessTransition;

    /**
     * Apply the supplied event to the current process state.
     *
     * @param   ProcessInstance   $process  Current process instance being read or transitioned.
     * @param   IntegrationEvent  $event    Versioned event being validated or processed.
     *
     * @return  ProcessTransition  Next state and requested durable effects.
     *
     * @since   2.0.0
     */
    public function apply(ProcessInstance $process, IntegrationEvent $event): ProcessTransition;
}
