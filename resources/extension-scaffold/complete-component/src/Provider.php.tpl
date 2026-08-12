<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@;

use @@PHP_NAMESPACE@@\Application\OverviewService;
use @@PHP_NAMESPACE@@\Definition\BusinessDefinitions;
use @@PHP_NAMESPACE@@\Delivery\Administrator\OverviewHandlerFactory as AdministratorOverviewFactory;
use @@PHP_NAMESPACE@@\Delivery\Portal\OverviewHandlerFactory as PortalOverviewFactory;
use @@PHP_NAMESPACE@@\Integration\DigestJobHandler;
use @@PHP_NAMESPACE@@\Integration\IntegrationDefinitions;
use @@PHP_NAMESPACE@@\Integration\IntegrationLedger;
use @@PHP_NAMESPACE@@\Integration\ItemDomainListener;
use @@PHP_NAMESPACE@@\Integration\ItemIntegrationConsumer;
use @@PHP_NAMESPACE@@\Integration\ItemProjectionBuilder;
use Kumwe\CMS\Application\Authorization\ResourcePolicyTarget;
use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Kumwe\CMS\Extension\Contribution\AdministratorNavigationDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorRouteDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorRouteHandlerFactory;
use Kumwe\CMS\Extension\Contribution\AdministratorViewDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorWorkspaceDefinition;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinition;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrar;
use Kumwe\CMS\Extension\Contribution\InterfaceSurfaceRegistrar;
use Kumwe\CMS\Extension\Contribution\ResourcePolicyDefinition;
use Kumwe\CMS\Extension\Runtime\ExtensionContainer;
use Kumwe\CMS\InterfaceStandard\SurfaceDefinition;
use Kumwe\CMS\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\CMS\Portal\Contribution\PortalRouteDefinition;
use Kumwe\CMS\Portal\Contribution\PortalRouteHandlerFactory;
use Kumwe\CMS\Portal\Contribution\PortalTemplateDefinition;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceDefinition;

/**
 * Registers the component services and reconciled runtime contributions.
 *
 * @since  2.0.0
 */
final class Provider implements ExtensionServiceProvider, ExtensionContributionProvider
{
    /**
     * Extension-local identifier of the bounded integration ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LEDGER = 'extension.@@EXTENSION_DOTTED@@.integration-ledger';

    /**
     * Extension-local identifier of the application service.
     *
     * @var    string
     * @since  2.0.0
     */
    private const SERVICE = 'extension.@@EXTENSION_DOTTED@@.overview';

    /**
     * Extension-local identifier of the administrator handler factory.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ADMINISTRATOR_FACTORY = 'extension.@@EXTENSION_DOTTED@@.administrator-factory';

    /**
     * Extension-local identifier of the portal handler factory.
     *
     * @var    string
     * @since  2.0.0
     */
    private const PORTAL_FACTORY = 'extension.@@EXTENSION_DOTTED@@.portal-factory';

    /**
     * Register only owner-namespaced application and delivery factories.
     *
     * @param   ExtensionContainer  $container  Restricted extension service surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(ExtensionContainer $container): void
    {
        $container->share(
            self::LEDGER,
            static fn (ExtensionContainer $container): IntegrationLedger => new IntegrationLedger(),
        );
        $container->share(
            self::SERVICE,
            static function (ExtensionContainer $container): OverviewService {
                $ledger = $container->get(self::LEDGER);
                if (!$ledger instanceof IntegrationLedger) {
                    throw new \LogicException('The component integration ledger is unavailable.');
                }

                return new OverviewService($ledger);
            },
        );
        $container->share(
            self::ADMINISTRATOR_FACTORY,
            static function (ExtensionContainer $container): AdministratorRouteHandlerFactory {
                $service = $container->get(self::SERVICE);
                if (!$service instanceof OverviewService) {
                    throw new \LogicException('The component overview service is unavailable.');
                }

                return new AdministratorOverviewFactory($service);
            },
        );
        $container->share(
            self::PORTAL_FACTORY,
            static function (ExtensionContainer $container): PortalRouteHandlerFactory {
                $service = $container->get(self::SERVICE);
                if (!$service instanceof OverviewService) {
                    throw new \LogicException('The component overview service is unavailable.');
                }

                return new PortalOverviewFactory($service);
            },
        );
    }

    /**
     * Register the executable contribution set that exactly mirrors the signed manifest.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Owner-bound typed contribution sink.
     * @param   ExtensionContainer              $container      Restricted extension service surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function contribute(ExtensionContributionRegistrar $contributions, ExtensionContainer $container): void
    {
        if (!$contributions instanceof InterfaceSurfaceRegistrar) {
            throw new \LogicException('The component KIS surface registrar is unavailable.');
        }
        $contributions->capability(new CapabilityDefinition(
            '@@EXTENSION_DOTTED@@.access',
            'Access @@LABEL_PHP@@',
            'Open the administrator and portal surfaces supplied by @@LABEL_PHP@@.',
        ));
        $contributions->resourcePolicy(new ResourcePolicyDefinition(
            '@@EXTENSION_DOTTED@@.surface',
            '@@EXTENSION_DOTTED@@.access',
            [
                new ResourcePolicyTarget('administrator_session'),
                new ResourcePolicyTarget('business_report', ['@@EXTENSION_DOTTED@@.item_report']),
                new ResourcePolicyTarget('portal_session'),
            ],
        ));
        foreach (BusinessDefinitions::all() as $definition) {
            $contributions->businessDefinition($definition);
        }
        $this->contributeInterface($contributions);
        $this->contributeAdministrator($contributions, $container);
        $this->contributePortal($contributions, $container);
        $this->contributeIntegration($contributions, $container);
    }

    /**
     * Register the administrator and portal surfaces mirrored in the manifest.
     *
     * @param   InterfaceSurfaceRegistrar  $contributions  Owner-bound KIS surface sink.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function contributeInterface(InterfaceSurfaceRegistrar $contributions): void
    {
        $owner = ContributionOwner::extension('@@EXTENSION_IDENTIFIER@@');
        $contributions->interfaceSurface(SurfaceDefinition::fromArray($owner, [
            'surface' => '@@EXTENSION_DOTTED@@.administrator.index',
            'standard' => 'kis-1.0',
            'area' => 'administrator',
            'actor' => 'administrator',
            'intent' => 'diagnostics',
            'resource' => 'component-overview',
            'purpose' => 'Review component activity and continue into its generated record workspaces.',
            'pattern' => 'diagnostics-workspace',
            'capabilities' => ['@@EXTENSION_DOTTED@@.access'],
            'states' => ['default', 'empty', 'dense', 'error', 'permission-reduced'],
            'customization' => [['slot' => 'density', 'scope' => 'user']],
            'responsive' => [
                ['element' => 'component-activity', 'priority' => 'essential', 'may_collapse' => false],
                ['element' => 'integration-status', 'priority' => 'secondary', 'may_collapse' => true],
            ],
            'icon' => 'extensions',
        ]));
        $contributions->interfaceSurface(SurfaceDefinition::fromArray($owner, [
            'surface' => '@@EXTENSION_DOTTED@@.portal.index',
            'standard' => 'kis-1.0',
            'area' => 'portal',
            'actor' => 'portal',
            'intent' => 'monitor',
            'resource' => 'component-status',
            'purpose' => 'Review the policy-filtered component activity available to this portal member.',
            'pattern' => 'status-workspace',
            'capabilities' => ['@@EXTENSION_DOTTED@@.access'],
            'states' => ['default', 'empty', 'error', 'permission-reduced', 'read-only'],
            'customization' => [
                ['slot' => 'density', 'scope' => 'user'],
                ['slot' => 'theme-mode', 'scope' => 'user'],
            ],
            'responsive' => [
                ['element' => 'component-status', 'priority' => 'essential', 'may_collapse' => false],
                ['element' => 'activity-summary', 'priority' => 'secondary', 'may_collapse' => true],
            ],
            'icon' => 'extensions',
        ]));
    }

    /**
     * Register the administrator declarations mirrored in the manifest.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Owner-bound contribution sink.
     * @param   ExtensionContainer              $container      Owner-bound service container.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function contributeAdministrator(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $contributions->administratorWorkspace(new AdministratorWorkspaceDefinition(
            '@@EXTENSION_DOTTED@@.workspace', '@@LABEL_PHP@@', 'Manage @@LABEL_PHP@@ records.', 200,
        ));
        $contributions->administratorNavigation(new AdministratorNavigationDefinition(
            '@@EXTENSION_DOTTED@@.navigation', '@@EXTENSION_DOTTED@@.workspace', '@@LABEL_PHP@@',
            'Open @@LABEL_PHP@@ administration.', '/', 'extensions', '@@EXTENSION_DOTTED@@.access', 10,
            '@@LABEL_PHP@@ component records',
            '@@EXTENSION_DOTTED@@.administrator.index',
        ));
        $contributions->administratorView(new AdministratorViewDefinition(
            '@@EXTENSION_DOTTED@@.administrator.index', 'index.twig',
        ));
        $factory = $container->get(self::ADMINISTRATOR_FACTORY);
        if (!$factory instanceof AdministratorRouteHandlerFactory) {
            throw new \LogicException('The component administrator handler factory is unavailable.');
        }
        $contributions->administratorRoute(new AdministratorRouteDefinition(
            '@@EXTENSION_DOTTED@@.administrator.index', '/', ['GET'], '@@EXTENSION_DOTTED@@.access',
            '@@EXTENSION_DOTTED@@.administrator.index',
        ), $factory);
    }

    /**
     * Register the portal declarations mirrored in the manifest.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Owner-bound contribution sink.
     * @param   ExtensionContainer              $container      Owner-bound service container.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function contributePortal(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $contributions->portalWorkspace(new PortalWorkspaceDefinition(
            '@@EXTENSION_DOTTED@@.portal.workspace', '@@LABEL_PHP@@', 'Use @@LABEL_PHP@@ in the portal.', 200,
        ));
        $contributions->portalNavigation(new PortalNavigationDefinition(
            '@@EXTENSION_DOTTED@@.portal.navigation', '@@EXTENSION_DOTTED@@.portal.workspace', '@@LABEL_PHP@@',
            'Open @@LABEL_PHP@@.', '/', 'extensions', '@@EXTENSION_DOTTED@@.access', 10,
            '@@LABEL_PHP@@ component records',
            '@@EXTENSION_DOTTED@@.portal.index',
        ));
        $contributions->portalTemplate(new PortalTemplateDefinition(
            '@@EXTENSION_DOTTED@@.portal.index', 'index.twig',
        ));
        $factory = $container->get(self::PORTAL_FACTORY);
        if (!$factory instanceof PortalRouteHandlerFactory) {
            throw new \LogicException('The component portal handler factory is unavailable.');
        }
        $contributions->portalRoute(new PortalRouteDefinition(
            '@@EXTENSION_DOTTED@@.portal.index', '/', ['GET'], '@@EXTENSION_DOTTED@@.access',
            '@@EXTENSION_DOTTED@@.portal.index',
        ), $factory);
    }

    /**
     * Register the complete executable event, job, projection, and report graph.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Owner-bound contribution sink.
     * @param   ExtensionContainer              $container      Owner-bound service container.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function contributeIntegration(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $ledger = $container->get(self::LEDGER);
        if (!$ledger instanceof IntegrationLedger) {
            throw new \LogicException('The component integration ledger is unavailable.');
        }
        $contributions->eventSchema(IntegrationDefinitions::eventSchema());
        $contributions->queue(IntegrationDefinitions::queue());
        $listener = IntegrationDefinitions::domainListener();
        $contributions->domainListener($listener, new ItemDomainListener($listener, $ledger));
        $consumer = IntegrationDefinitions::consumer();
        $contributions->eventConsumer($consumer, new ItemIntegrationConsumer($consumer, $ledger));
        $contributions->jobHandler(IntegrationDefinitions::job(), new DigestJobHandler($ledger));
        $contributions->schedule(IntegrationDefinitions::schedule());
        $projection = IntegrationDefinitions::projection();
        $contributions->projection($projection, new ItemProjectionBuilder($projection));
        $contributions->report(IntegrationDefinitions::report());
    }
}
