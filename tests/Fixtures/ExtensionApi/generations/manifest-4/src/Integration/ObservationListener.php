<?php

declare(strict_types=1);

namespace KumweContract\ManifestFour\Integration;

use Kumwe\CMS\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\CMS\BusinessIntegration\Domain\DomainEvent;
use Kumwe\CMS\BusinessIntegration\Domain\DomainListenerDefinition;

/**
 * Synchronous listener half of the manifest-4 compatibility package.
 *
 * @since  2.0.0
 */
final readonly class ObservationListener implements DomainEventHandler
{
    /**
     * Bind the executable listener to the declaration the manifest signed.
     *
     * @param  DomainListenerDefinition  $definition  Exact listener declaration.
     * @param  ObservationLedger         $ledger      Process-local evidence sink.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DomainListenerDefinition $definition,
        private ObservationLedger $ledger,
    ) {
    }

    /**
     * Return the signed listener contract implemented here.
     *
     * @return  DomainListenerDefinition  The declaration handed in at construction.
     *
     * @since   2.0.0
     */
    public function definition(): DomainListenerDefinition
    {
        return $this->definition;
    }

    /**
     * Record that the transaction-local listener ran, without any external effect.
     *
     * @param   DomainEvent  $event  Mutation event executing inside the authoritative transaction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(DomainEvent $event): void
    {
        $this->ledger->record('domain-listener');
    }
}
