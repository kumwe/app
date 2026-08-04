<?php

declare(strict_types=1);

namespace KumweExample\AuditListener;

use Joomla\DI\Container;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\EventInterface;
use Kumwe\CMS\Extension\Runtime\RuntimeExtension;
use Mezzio\Application;
use Psr\Log\LoggerInterface;

final class Provider implements RuntimeExtension
{
    public function register(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        $container->get(DispatcherInterface::class)->addListener(
            'onKumweExtensionAfterActivate',
            function (EventInterface $event) use ($container): void {
                $container->get(LoggerInterface::class)->info('An extension was activated.', [
                    'identifier' => $event->getArgument('identifier'),
                ]);
            },
        );
    }

    public function registerRoutes(Application $application): void
    {
    }
}
