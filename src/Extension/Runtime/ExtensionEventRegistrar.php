<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Joomla\Event\EventInterface;

interface ExtensionEventRegistrar
{
    /** @param callable(EventInterface): void $listener */
    public function listen(string $event, callable $listener): void;
}
