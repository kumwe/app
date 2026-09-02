<?php

declare(strict_types=1);

namespace KumweExample\AuditListener\Integration;

use InvalidArgumentException;
use Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDefinition;

/**
 * Observes every business-record mutation the platform publishes and records its identity.
 *
 * The handler runs inside the authoritative transaction, so it does nothing but validate and record:
 * it refuses a declaration that is not its own, refuses an event outside the declared contract, and
 * otherwise appends the event's identity to the bounded ledger. Anything that would leave the process
 * belongs in a job or a consumer of the committed outbox, never here.
 *
 * @since  2.0.0
 */
final readonly class MutationAuditListener implements DomainEventHandler
{
    /**
     * Bind the executable listener to the declaration it answers for and the ledger it writes.
     *
     * @param  string       $listenerId  Identifier of the signed `domain_listeners` declaration this handler serves.
     * @param  AuditLedger  $ledger      Bounded evidence sink.
     *
     * @since  2.0.0
     */
    public function __construct(private string $listenerId, private AuditLedger $ledger)
    {
    }

    /**
     * Record one routed mutation after proving it arrived under the declared contract.
     *
     * @param   DomainListenerDefinition  $definition  Host-validated exact signed listener declaration.
     * @param   DomainEvent               $event       Mutation event executing inside the authoritative transaction.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the declaration is foreign or the event is outside the declared contract.
     *
     * @since   2.0.0
     */
    public function handle(DomainListenerDefinition $definition, DomainEvent $event): void
    {
        if ($definition->identifier() !== $this->listenerId) {
            throw new InvalidArgumentException('The audit listener received a foreign declaration.');
        }
        if (!$definition->accepts($event->eventType(), $event->schemaVersion(), $event->sensitivity())) {
            throw new InvalidArgumentException('The audit listener requires its declared event contract.');
        }

        $this->ledger->record($event->eventId(), $event->eventType(), $event->schemaVersion());
    }
}
