<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use InvalidArgumentException;
use Joomla\Event\DispatcherInterface;

/**
 * Extension event registrar backed by the Joomla dispatcher the host application already runs on.
 *
 * Extensions are handed this instead of the dispatcher so that subscribing is the only thing they can
 * do: the contract has no way to dispatch an event, drop another listener, or set a priority. The name
 * filter is the second half of that boundary — only events in the `onKumwe*` domain namespace are
 * reachable, so an extension cannot latch onto a framework hook or another extension's internals by
 * guessing its event name. `ExtensionRuntimeLoader` wraps this registrar in
 * `TrustEnforcingExtensionEventRegistrar`, which is what stops a quarantined extension's listeners
 * from firing.
 *
 * @since  2.0.0
 */
final readonly class JoomlaExtensionEventRegistrar implements ExtensionEventRegistrar
{
    /**
     * Bind the registrar to the dispatcher that will invoke the registered listeners.
     *
     * @param  DispatcherInterface  $events  Host application dispatcher listeners are attached to.
     *
     * @since  2.0.0
     */
    public function __construct(private DispatcherInterface $events)
    {
    }

    /**
     * Subscribe a listener to a named Kumwe domain event.
     *
     * Registration is append-only and unconditional: the listener is attached at the dispatcher's
     * default priority and there is no way to remove it again for the life of the process.
     *
     * @param   string                                        $event     Domain event name; `onKumwe`
     *          followed by an upper-case letter
     *          and 2 to 126 further alphanumerics.
     * @param   callable(\Joomla\Event\EventInterface): void  $listener  Invoked with the dispatched event.
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

        $this->events->addListener($event, $listener);
    }
}
