<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\Extension\Spi\Runtime\ExtensionEvent;
use Laminas\EventManager\EventManagerInterface;

/**
 * Extension event registrar backed by the Laminas event manager the host application runs on.
 *
 * Extensions are handed this instead of the event manager so that subscribing is the only thing they can
 * do: the contract has no way to dispatch an event, drop another listener, or set a priority. The name
 * filter is the second half of that boundary — only events in the `onKumwe*` domain namespace are
 * reachable, so an extension cannot latch onto a framework hook, the Laminas wildcard channel, or another
 * extension's internals by guessing its event name. `ExtensionRuntimeLoader` wraps this registrar in
 * `TrustEnforcingExtensionEventRegistrar`, which is what stops a quarantined extension's listeners
 * from firing.
 *
 * @since  2.0.0
 */
final readonly class LaminasExtensionEventRegistrar implements ExtensionEventRegistrar
{
    /**
     * Bind the registrar to the event manager that will invoke the registered listeners.
     *
     * @param  EventManagerInterface  $events  Host application event manager listeners are attached to.
     *
     * @since  2.0.0
     */
    public function __construct(private EventManagerInterface $events)
    {
    }

    /**
     * Subscribe a listener to a named Kumwe domain event.
     *
     * Registration is append-only and unconditional: the listener is attached at the event manager's
     * default priority and there is no way to remove it again for the life of the process.
     *
     * @param   string                          $event     Domain event name; `onKumwe` followed by an
     *          upper-case letter and 2 to 126 further alphanumerics.
     * @param   callable(ExtensionEvent): void  $listener  Invoked with the dispatched event.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the event name falls outside the `onKumwe*` namespace.
     *
     * @since   2.0.0
     */
    public function listen(string $event, callable $listener): void
    {
        if (preg_match('/^onKumwe[A-Z][A-Za-z0-9]{2,126}$/D', $event) !== 1) {
            throw new InvalidArgumentException('Extensions can subscribe only to named Kumwe domain events.');
        }

        $this->events->attach($event, $listener);
    }
}
