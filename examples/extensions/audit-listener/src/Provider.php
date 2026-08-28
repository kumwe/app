<?php

declare(strict_types=1);

namespace KumweExample\AuditListener;

use Kumwe\App\Extension\Runtime\ExtensionContainer;
use Kumwe\Extension\Spi\Runtime\ExtensionEvent;
use Kumwe\App\Extension\Runtime\ExtensionEventRegistrar;
use Kumwe\App\Extension\Runtime\ExtensionRouteRegistrar;
use Kumwe\App\Extension\Runtime\RuntimeExtension;

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
            function (ExtensionEvent $event): void {
                $event->getArgument('identifier');
            },
        );
    }

    public function registerRoutes(ExtensionRouteRegistrar $routes): void
    {
    }
}
