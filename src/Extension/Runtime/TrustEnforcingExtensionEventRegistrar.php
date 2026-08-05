<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Joomla\Event\EventInterface;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;

/** Prevents a quarantined extension's already-registered listeners from running. */
final readonly class TrustEnforcingExtensionEventRegistrar implements ExtensionEventRegistrar
{
    public function __construct(
        private ExtensionEventRegistrar $inner,
        private TrustStore $trust,
        private string $extension,
    ) {
    }

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
