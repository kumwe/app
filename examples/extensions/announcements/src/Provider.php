<?php

declare(strict_types=1);

namespace KumweExample\Announcements;

use LogicException;
use Kumwe\Extension\Spi\Binding\ExtensionBindingProvider;
use Kumwe\Extension\Spi\Binding\ExtensionBindingRegistrar;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteHandlerFactory;
use Kumwe\Extension\Spi\Application\ExtensionServiceProvider;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;
use KumweExample\Announcements\Application\AnnouncementService;
use KumweExample\Announcements\Delivery\AnnouncementsPageHandlerFactory;
use KumweExample\Announcements\Presentation\SeverityFieldPresenter;

/** SDK-native provider whose signed manifest is the sole source of declarations. */
final class Provider implements ExtensionServiceProvider, ExtensionBindingProvider
{
    private const string FACTORY = 'extension.kumwe.announcements-example.administrator-handler-factory';

    public function register(ExtensionContainer $container): void
    {
        $container->share(
            'extension.kumwe.announcements-example.application-service',
            static fn (ExtensionContainer $container): AnnouncementService => new AnnouncementService(),
        );
        $container->share(
            self::FACTORY,
            static function (ExtensionContainer $container): AdministratorRouteHandlerFactory {
                $service = $container->get('extension.kumwe.announcements-example.application-service');
                if (!$service instanceof AnnouncementService) {
                    throw new LogicException('The announcements application service is unavailable.');
                }

                return new AnnouncementsPageHandlerFactory($service);
            },
        );
    }

    public function bind(ExtensionBindingRegistrar $bindings, ExtensionContainer $container): void
    {
        $factory = $container->get(self::FACTORY);
        if (!$factory instanceof AdministratorRouteHandlerFactory) {
            throw new LogicException('The announcements administrator handler factory is unavailable.');
        }

        $bindings->fieldPresenter(
            'kumwe.announcements-example.severity',
            new SeverityFieldPresenter(),
        );
        $bindings->administratorRoute('kumwe.announcements-example.index', $factory);
    }
}
