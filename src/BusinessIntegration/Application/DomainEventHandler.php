<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use Kumwe\CMS\BusinessIntegration\Domain\DomainEvent;
use Kumwe\CMS\BusinessIntegration\Domain\DomainListenerDefinition;

/**
 * Deterministic synchronous listener participating in the authoritative transaction.
 *
 * @since  2.0.0
 */
interface DomainEventHandler
{
    /** @return DomainListenerDefinition Trusted data-only listener contract. @since 2.0.0 */
    public function definition(): DomainListenerDefinition;

    /** @return void @since 2.0.0 */
    public function handle(DomainEvent $event): void;
}
