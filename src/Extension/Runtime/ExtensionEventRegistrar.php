<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Joomla\Event\EventInterface;

/**
 * Subscription surface an extension is handed so it can react to Kumwe domain events.
 *
 * Extensions never see the dispatcher itself. This contract can only attach a listener, so an extension
 * cannot dispatch an event, detach somebody else's listener, or reorder the ones already attached. The
 * two implementations narrow it further: `JoomlaExtensionEventRegistrar` accepts only names in the
 * `onKumwe*` domain namespace, and `TrustEnforcingExtensionEventRegistrar` — which the runtime loader
 * wraps around it — re-checks trust on every dispatch so a quarantined extension's already-attached
 * listeners stop running.
 *
 * @since  2.0.0
 */
interface ExtensionEventRegistrar
{
    /**
     * Attach a listener to a named domain event.
     *
     * @param   string                          $event     Name of the domain event to subscribe to.
     * @param   callable(EventInterface): void  $listener  Invoked with each matching event once it is
     *          dispatched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function listen(string $event, callable $listener): void;
}
