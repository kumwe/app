<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDefinition;

/**
 * Synchronously invokes matching domain listeners after validating the event contract.
 *
 * @since  2.0.0
 */
final readonly class DomainEventDispatcher
{
    /**
     * Synchronous domain-event handlers searched in deterministic priority order.
     *
     * @var    list<array{definition: DomainListenerDefinition, handler: DomainEventHandler}>  Ordered listeners.
     * @since  2.0.0
     */
    private array $handlers;

    /**
     * Bind deterministic listeners to the trusted event catalog.
     *
     * @param EventContractRegistry $contracts Exact runtime event catalog.
     * @param  iterable<array{definition: DomainListenerDefinition, handler: DomainEventHandler}>  $handlers
     *         Canonical declarations paired with their owner-bound implementations.
     *
     * @since  2.0.0
     */
    public function __construct(private EventContractRegistry $contracts, iterable $handlers)
    {
        $resolved = [];
        foreach ($handlers as $entry) {
            foreach ($entry['definition']->schemaVersions() as $version) {
                $this->contracts->schema($entry['definition']->eventType(), $version);
            }
            $resolved[] = $entry;
        }
        usort(
            $resolved,
            static function (array $left, array $right): int {
                $priority = $right['definition']->priority() <=> $left['definition']->priority();

                return $priority !== 0
                    ? $priority
                    : strcmp($left['definition']->identifier(), $right['definition']->identifier());
            },
        );
        $this->handlers = $resolved;
    }

    /**
     * Validate and invoke every matching listener; an exception is allowed to roll back the caller.
     *
     * @param   DomainEvent  $event  Transaction-local fact.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function dispatch(DomainEvent $event): void
    {
        $this->contracts->assertEvent($event);
        foreach ($this->handlers as $entry) {
            $definition = $entry['definition'];
            if (
                $definition->accepts(
                    $event->eventType(),
                    $event->schemaVersion(),
                    $event->sensitivity(),
                )
            ) {
                $entry['handler']->handle($definition, $event);
            }
        }
    }
}
