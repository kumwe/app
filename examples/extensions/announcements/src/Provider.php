<?php

declare(strict_types=1);

namespace KumweExample\Announcements;

use Kumwe\CMS\Extension\Contribution\AdministratorNavigationDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorRouteDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorRouteHandlerFactory;
use Kumwe\CMS\Extension\Contribution\AdministratorViewDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorWorkspaceDefinition;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinition;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrar;
use Kumwe\CMS\Extension\Runtime\ExtensionContainer;
use Kumwe\CMS\Extension\Runtime\ExtensionRouteRegistrar;
use Kumwe\CMS\Extension\Runtime\RuntimeExtension;
use Kumwe\CMS\Site\Application\SiteSettings;
use KumweExample\Announcements\Application\AnnouncementService;
use KumweExample\Announcements\Delivery\AnnouncementsPageHandlerFactory;

final class Provider implements RuntimeExtension, ExtensionContributionProvider
{
    private const FACTORY = 'extension.kumwe.announcements-example.administrator-handler-factory';

    public function register(ExtensionContainer $container): void
    {
        $container->share(
            'extension.kumwe.announcements-example.application-service',
            static function (ExtensionContainer $container): AnnouncementService {
                $settings = $container->get(SiteSettings::class);
                if (!$settings instanceof SiteSettings) {
                    throw new \LogicException('The site settings service is unavailable.');
                }
                return new AnnouncementService($settings);
            },
        );
        $container->share(
            self::FACTORY,
            static function (ExtensionContainer $container): AdministratorRouteHandlerFactory {
                $service = $container->get('extension.kumwe.announcements-example.application-service');
                if (!$service instanceof AnnouncementService) {
                    throw new \LogicException('The announcements application service is unavailable.');
                }
                return new AnnouncementsPageHandlerFactory($service);
            },
        );
    }

    public function contribute(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $contributions->capability(new CapabilityDefinition(
            'kumwe.announcements-example.manage',
            'Manage announcements example',
            'Open and manage the announcements conformance workspace.',
        ));
        $contributions->fieldType(BusinessDefinitions::severity());
        foreach (BusinessDefinitions::all() as $definition) {
            $contributions->businessDefinition($definition);
        }
        $contributions->administratorWorkspace(new AdministratorWorkspaceDefinition(
            'kumwe.announcements-example.workspace',
            'Announcements',
            'Example component workspace contributions.',
            150,
        ));
        $contributions->administratorNavigation(new AdministratorNavigationDefinition(
            'kumwe.announcements-example.navigation',
            'kumwe.announcements-example.workspace',
            'Announcements',
            'Open the graphical announcements example',
            '/',
            'extensions',
            'kumwe.announcements-example.manage',
            10,
            'announcements example component contribution',
        ));
        $contributions->administratorView(new AdministratorViewDefinition(
            'kumwe.announcements-example.index',
            'announcements.twig',
        ));
        $factory = $container->get(self::FACTORY);
        if (!$factory instanceof AdministratorRouteHandlerFactory) {
            throw new \LogicException('The announcements administrator handler factory is unavailable.');
        }
        $contributions->administratorRoute(new AdministratorRouteDefinition(
            'kumwe.announcements-example.index',
            '/',
            ['GET'],
            'kumwe.announcements-example.manage',
            'kumwe.announcements-example.index',
        ), $factory);
    }

    public function boot(ExtensionContainer $container): void
    {
    }

    public function registerRoutes(ExtensionRouteRegistrar $routes): void
    {
    }
}
