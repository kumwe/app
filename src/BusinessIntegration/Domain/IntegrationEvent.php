<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

/**
 * Durable fact written to the transactional outbox for at-least-once delivery.
 *
 * @since  2.0.0
 */
final readonly class IntegrationEvent extends EventEnvelope
{
    /**
     * Copy a domain fact into the durable integration-event type without changing its identity.
     *
     * @param   DomainEvent  $event  Transaction-local fact approved for durable publication.
     *
     * @return  self  Integration event carrying the same envelope and payload.
     *
     * @since   2.0.0
     */
    public static function fromDomain(DomainEvent $event): self
    {
        return self::fromArray($event->toArray());
    }
}
