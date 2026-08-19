<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Integration;

use InvalidArgumentException;
use Kumwe\App\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\App\BusinessIntegration\Domain\DomainEvent;
use Kumwe\App\BusinessIntegration\Domain\DomainListenerDefinition;

/**
 * Validates and records transaction-local item-observed facts.
 *
 * @since  2.0.0
 */
final readonly class ItemDomainListener implements DomainEventHandler
{
    /**
     * Bind listener observations to the shared bounded ledger.
     *
     * @param  DomainListenerDefinition  $definition  Exact signed listener declaration.
     * @param  IntegrationLedger         $ledger      Bounded diagnostic event ledger.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DomainListenerDefinition $definition,
        private IntegrationLedger $ledger,
    ) {
    }

    /**
     * Return the exact signed listener declaration implemented by this class.
     *
     * @return  DomainListenerDefinition  Manifest-reconciled listener contract.
     *
     * @since   2.0.0
     */
    public function definition(): DomainListenerDefinition
    {
        return $this->definition;
    }

    /**
     * Validate direct invocations and record the immutable event identity.
     *
     * @param   DomainEvent  $event  Transaction-local item-observed event.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When called with an undeclared contract revision.
     *
     * @since   2.0.0
     */
    public function handle(DomainEvent $event): void
    {
        if (!$this->definition->accepts(
            $event->eventType(),
            $event->schemaVersion(),
            $event->sensitivity(),
        )) {
            throw new InvalidArgumentException('The item listener received an unsupported event contract.');
        }
        $this->ledger->recordDomain($event);
    }
}
