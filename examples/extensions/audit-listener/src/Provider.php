<?php

declare(strict_types=1);

namespace KumweExample\AuditListener;

use Joomla\Event\EventInterface;
use Kumwe\CMS\Extension\Runtime\ExtensionContainer;
use Kumwe\CMS\Extension\Runtime\ExtensionEventRegistrar;
use Kumwe\CMS\Extension\Runtime\ExtensionRouteRegistrar;
use Kumwe\CMS\Extension\Runtime\RuntimeExtension;

final class Provider implements RuntimeExtension
{
    public function register(ExtensionContainer $container): void
    {
    }

    public function boot(ExtensionContainer $container): void
    {
        $events = $container->get(ExtensionEventRegistrar::class);
        if (!$events instanceof ExtensionEventRegistrar) {
            throw new \LogicException('The safe extension event registrar is unavailable.');
        }
        $events->listen(
            'onKumweExtensionAfterActivate',
            function (EventInterface $event): void {
                $event->getArgument('identifier');
            },
        );
    }

    public function registerRoutes(ExtensionRouteRegistrar $routes): void
    {
    }
}
