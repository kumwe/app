<?php

declare(strict_types=1);

namespace KumweExample\Announcements;

use Joomla\DI\Container;
use Kumwe\CMS\Extension\Runtime\RuntimeExtension;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Application;

final class Provider implements RuntimeExtension
{
    public function register(Container $container): void
    {
        $container->share('kumwe.example.announcements.handler', static fn (): callable =>
            static fn (): JsonResponse => new JsonResponse(['announcements' => []]), true);
    }

    public function boot(Container $container): void
    {
    }

    public function registerRoutes(Application $application): void
    {
        $application->get(
            '/announcements',
            'kumwe.example.announcements.handler',
            'announcements.index',
        );
    }
}
