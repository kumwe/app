<?php

declare(strict_types=1);

namespace KumweExample\Announcements;

use Kumwe\App\Application\Authorization\ResourcePolicyTarget;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContribution;
use Kumwe\App\Extension\Contribution\AdministratorNavigationDefinition;
use Kumwe\App\Extension\Contribution\AdministratorRouteDefinition;
use Kumwe\App\Extension\Contribution\AdministratorRouteHandlerFactory;
use Kumwe\App\Extension\Contribution\AdministratorViewDefinition;
use Kumwe\App\Extension\Contribution\AdministratorWorkspaceDefinition;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrar;
use Kumwe\App\Extension\Contribution\InterfaceSurfaceRegistrar;
use Kumwe\App\Extension\Contribution\ResourcePolicyDefinition;
use Kumwe\App\Extension\Runtime\ExtensionContainer;
use Kumwe\App\Extension\Runtime\ExtensionRouteRegistrar;
use Kumwe\App\Extension\Runtime\RuntimeExtension;
use Kumwe\App\InterfaceStandard\SurfaceDefinition;
use Kumwe\App\Site\Application\SiteSettings;
use KumweExample\Announcements\Application\AnnouncementService;
use KumweExample\Announcements\Delivery\AnnouncementsPageHandlerFactory;
use KumweExample\Announcements\Presentation\SeverityFieldPresenter;

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
        if (!$contributions instanceof InterfaceSurfaceRegistrar) {
            throw new \LogicException('The announcements KIS surface registrar is unavailable.');
        }
        $contributions->capability(new CapabilityDefinition(
            'kumwe.announcements-example.manage',
            'Manage announcements example',
            'Open and manage the announcements conformance workspace.',
        ));
        $contributions->resourcePolicy(new ResourcePolicyDefinition(
            'kumwe.announcements-example.administrator',
            'kumwe.announcements-example.manage',
            [new ResourcePolicyTarget('administrator_session')],
        ));
        $severity = BusinessDefinitions::severity();
        $contributions->fieldType($severity);
        $contributions->fieldPresentation(
            new FieldPresentationContribution($severity->id, FieldPresentationContext::cases()),
            new SeverityFieldPresenter(),
        );
        foreach (BusinessDefinitions::all() as $definition) {
            $contributions->businessDefinition($definition);
        }
        $contributions->interfaceSurface(SurfaceDefinition::fromArray(
            ContributionOwner::extension('kumwe/announcements-example'),
            [
                'surface' => 'kumwe.announcements-example.index',
                'standard' => 'kis-1.0',
                'area' => 'administrator',
                'actor' => 'administrator',
                'intent' => 'collection',
                'resource' => 'announcement-workspace',
                'purpose' => 'Browse extension-owned announcements and continue into their generated '
                    . 'management workspaces.',
                'pattern' => 'collection-workspace',
                'capabilities' => ['kumwe.announcements-example.manage'],
                'states' => [
                    'default',
                    'empty',
                    'dense',
                    'error',
                    'permission-reduced',
                    'read-only',
                ],
                'customization' => [['slot' => 'density', 'scope' => 'user']],
                'responsive' => [
                    [
                        'element' => 'announcement-work',
                        'priority' => 'essential',
                        'may_collapse' => false,
                    ],
                    [
                        'element' => 'generated-workspaces',
                        'priority' => 'secondary',
                        'may_collapse' => true,
                    ],
                ],
                'icon' => 'extensions',
            ],
        ));
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
            'kumwe.announcements-example.index',
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
