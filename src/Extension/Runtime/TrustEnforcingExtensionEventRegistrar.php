<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Joomla\Event\EventInterface;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;

/**
 * Extension event registrar that re-checks trust at dispatch time instead of at subscription time.
 *
 * Listeners are attached once, while the runtime map is loaded, and nothing detaches them again for the
 * life of the process — so without this decorator an extension quarantined an hour later would keep
 * reacting to domain events until the next deployment. `ExtensionRuntimeLoader` therefore wraps the
 * registrar it hands each extension in this one, which substitutes a closure that takes the
 * installation-wide lifecycle lock and re-establishes the extension's trust before the real listener
 * runs. A signing key revoked long after installation silences the listener on the very next dispatch,
 * and the refusal is left to propagate to whoever dispatched the event rather than being swallowed.
 *
 * @since  2.0.0
 */
final readonly class TrustEnforcingExtensionEventRegistrar implements ExtensionEventRegistrar
{
    /**
     * Wrap the registrar one extension would otherwise subscribe through.
     *
     * @param  ExtensionEventRegistrar  $inner      Registrar the wrapped listener is actually attached
     *         to, in the shipped wiring `JoomlaExtensionEventRegistrar`.
     * @param  TrustStore               $trust      Trust boundary consulted on every dispatch, and the
     *         owner of the lifecycle lock the check runs inside.
     * @param  string                   $extension  `vendor/name` of the extension whose trust decides
     *         whether these listeners still run.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionEventRegistrar $inner,
        private TrustStore $trust,
        private string $extension,
    ) {
    }

    /**
     * Attach a listener that runs only while its extension is still active and still trusted.
     *
     * Subscription itself is unconditional and is delegated straight to the inner registrar; the trust
     * check is deferred to each dispatch. Because that check runs inside the lifecycle lock, a listener
     * cannot execute while an install, upgrade or quarantine is in flight, and an extension that fails
     * enforcement is quarantined by the very dispatch that discovers it.
     *
     * @param   string                          $event     Name of the domain event to subscribe to.
     * @param   callable(EventInterface): void  $listener  Invoked with the dispatched event, once the
     *          extension's trust has been re-established.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function listen(string $event, callable $listener): void
    {
        $this->inner->listen($event, function (EventInterface $domainEvent) use ($listener): void {
            $this->trust->synchronizedLifecycle(function () use ($listener, $domainEvent): void {
                $this->trust->enforceRuntimeTrust($this->extension);
                $listener($domainEvent);
            });
        });
    }
}
