<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use Kumwe\App\BusinessIntegration\Domain\DomainEvent;

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
     * @var    list<DomainEventHandler>  Ordered listeners.
     * @since  2.0.0
     */
    private array $handlers;

    /**
     * Bind deterministic listeners to the trusted event catalog.
     *
     * @param  EventContractRegistry         $contracts  Exact runtime event catalog.
     * @param  iterable<DomainEventHandler>  $handlers   Synchronous listener instances in dispatch order.
     *
     * @since  2.0.0
     */
    public function __construct(private EventContractRegistry $contracts, iterable $handlers)
    {
        $resolved = [];
        foreach ($handlers as $handler) {
            foreach ($handler->definition()->schemaVersions() as $version) {
                $this->contracts->schema($handler->definition()->eventType(), $version);
            }
            $resolved[] = $handler;
        }
        usort(
            $resolved,
            static function (DomainEventHandler $left, DomainEventHandler $right): int {
                $priority = $right->definition()->priority() <=> $left->definition()->priority();

                return $priority !== 0
                    ? $priority
                    : strcmp($left->definition()->identifier(), $right->definition()->identifier());
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
        foreach ($this->handlers as $handler) {
            if (
                $handler->definition()->accepts(
                    $event->eventType(),
                    $event->schemaVersion(),
                    $event->sensitivity(),
                )
            ) {
                $handler->handle($event);
            }
        }
    }
}
