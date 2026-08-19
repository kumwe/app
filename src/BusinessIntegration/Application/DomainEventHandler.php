<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use Kumwe\App\BusinessIntegration\Domain\DomainEvent;
use Kumwe\App\BusinessIntegration\Domain\DomainListenerDefinition;

/**
 * Deterministic synchronous listener participating in the authoritative transaction.
 *
 * @since  2.0.0
 */
interface DomainEventHandler
{
    /**
     * Return the signed contribution definition implemented by this handler.
     *
     * @return  DomainListenerDefinition  Trusted data-only listener contract.
     *
     * @since   2.0.0
     */
    public function definition(): DomainListenerDefinition;

    /**
     * Handle the transaction-local event before its authoritative mutation commits.
     *
     * @param   DomainEvent  $event  Versioned event being validated or processed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(DomainEvent $event): void;
}
