<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use Joomla\Event\DispatcherInterface;

final readonly class JoomlaExtensionEventRegistrar implements ExtensionEventRegistrar
{
    public function __construct(private DispatcherInterface $events)
    {
    }

    public function listen(string $event, callable $listener): void
    {
        if (preg_match('/^onKumwe[A-Z][A-Za-z0-9]{2,126}$/D', $event) !== 1) {
            throw new InvalidArgumentException('Extensions can subscribe only to named Kumwe domain events.');
        }

        $this->events->addListener($event, $listener);
    }
}
