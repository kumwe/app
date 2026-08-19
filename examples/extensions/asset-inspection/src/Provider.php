<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection;

use LogicException;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\Extension\Contribution\AdministratorRouteHandlerFactory;
use Kumwe\App\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrar;
use Kumwe\App\Extension\Contribution\InterfaceSurfaceRegistrar;
use Kumwe\App\Extension\Runtime\ExtensionContainer;
use Kumwe\App\Extension\Runtime\ExtensionRouteRegistrar;
use Kumwe\App\Extension\Runtime\RuntimeExtension;
use Kumwe\App\Portal\Contribution\PortalRouteHandlerFactory;
use KumweExample\AssetInspection\Application\InspectionAccessPolicy;
use KumweExample\AssetInspection\Application\InspectionOverviewService;
use KumweExample\AssetInspection\Application\InspectionPolicyProfile;
use KumweExample\AssetInspection\Application\InspectionSummaryViewHandler;
use KumweExample\AssetInspection\Delivery\Administrator\InspectionOverviewHandlerFactory;
use KumweExample\AssetInspection\Delivery\Portal\InspectionStatusHandlerFactory;
use KumweExample\AssetInspection\Integration\InspectionActivityProjectionBuilder;
use KumweExample\AssetInspection\Integration\InspectionMutationConsumer;
use KumweExample\AssetInspection\Integration\InspectionMutationListener;
use KumweExample\AssetInspection\Integration\IntegrationLedger;
use KumweExample\AssetInspection\Integration\ReviewOverdueInspectionJob;

/**
 * Registers the neutral asset-inspection proof through the signed schema-4 contribution SPI.
 *
 * @since  2.0.0
 */
final class Provider implements RuntimeExtension, ExtensionContributionProvider
{
    /** @var string Owner-scoped diagnostic ledger service. @since 2.0.0 */
    private const LEDGER = 'extension.kumwe.asset-inspection-example.integration-ledger';

    /** @var string Owner-scoped signed row/field policy profile. @since 2.0.0 */
    private const POLICY_PROFILE = 'extension.kumwe.asset-inspection-example.policy-profile';

    /** @var string Owner-scoped access-policy service. @since 2.0.0 */
    private const POLICY = 'extension.kumwe.asset-inspection-example.access-policy';

    /** @var string Owner-scoped overview application service. @since 2.0.0 */
    private const OVERVIEW = 'extension.kumwe.asset-inspection-example.overview';

    /**
     * Owner-scoped service resolving the policy-filtered generated custom-view handler.
     *
     * @var    string
     * @since  2.0.0
     */
    private const SUMMARY_VIEW = 'extension.kumwe.asset-inspection-example.summary-view';

    /** @var string Owner-scoped administrator handler factory. @since 2.0.0 */
    private const ADMINISTRATOR_FACTORY = 'extension.kumwe.asset-inspection-example.administrator-factory';

    /** @var string Owner-scoped portal handler factory. @since 2.0.0 */
    private const PORTAL_FACTORY = 'extension.kumwe.asset-inspection-example.portal-factory';

    /**
     * Register owner-scoped services without reaching outside the restricted extension container.
     *
     * @param   ExtensionContainer  $container  Restricted service surface for this trusted package.
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
            self::POLICY_PROFILE,
            static fn (ExtensionContainer $container): InspectionPolicyProfile =>
                InspectionPolicyProfile::fromPackage(),
        );
        $container->share(
            self::POLICY,
            static function (ExtensionContainer $container): InspectionAccessPolicy {
                $profile = $container->get(self::POLICY_PROFILE);
                if (!$profile instanceof InspectionPolicyProfile) {
                    throw new LogicException('The asset-inspection policy profile is unavailable.');
                }

                return new InspectionAccessPolicy($profile);
            },
        );
        $container->share(
            self::OVERVIEW,
            static function (ExtensionContainer $container): InspectionOverviewService {
                $policy = $container->get(self::POLICY);
                $ledger = $container->get(self::LEDGER);
                if (!$policy instanceof InspectionAccessPolicy || !$ledger instanceof IntegrationLedger) {
                    throw new LogicException('The asset-inspection example application services are unavailable.');
                }

                return new InspectionOverviewService($policy, $ledger);
            },
        );
        $container->share(
            self::SUMMARY_VIEW,
            static function (ExtensionContainer $container): InspectionSummaryViewHandler {
                $records = $container->get(BusinessRecordService::class);
                if (!$records instanceof BusinessRecordService) {
                    throw new LogicException('The asset-inspection custom-view record service is unavailable.');
                }

                return new InspectionSummaryViewHandler($records);
            },
        );
        $container->share(
            self::ADMINISTRATOR_FACTORY,
            static function (ExtensionContainer $container): AdministratorRouteHandlerFactory {
                $overview = $container->get(self::OVERVIEW);
                if (!$overview instanceof InspectionOverviewService) {
                    throw new LogicException('The asset-inspection administrator overview is unavailable.');
                }

                return new InspectionOverviewHandlerFactory($overview);
            },
        );
        $container->share(
            self::PORTAL_FACTORY,
            static function (ExtensionContainer $container): PortalRouteHandlerFactory {
                $overview = $container->get(self::OVERVIEW);
                if (!$overview instanceof InspectionOverviewService) {
                    throw new LogicException('The asset-inspection portal overview is unavailable.');
                }

                return new InspectionStatusHandlerFactory($overview);
            },
        );
    }

    /**
     * Reconcile every executable contribution with the exact signed manifest declaration.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Owner-bound contribution sink.
     * @param   ExtensionContainer              $container      Restricted owner-scoped services.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function contribute(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $declarations = Definitions::all();
        foreach ($declarations->capabilities() as $definition) {
            $contributions->capability($definition);
        }
        foreach ($declarations->resourcePolicies() as $definition) {
            $contributions->resourcePolicy($definition);
        }
        if (!$contributions instanceof InterfaceSurfaceRegistrar) {
            throw new LogicException('The asset-inspection KIS surface registrar is unavailable.');
        }
        foreach ($declarations->interfaceSurfaces() as $definition) {
            $contributions->interfaceSurface($definition);
        }
        foreach ($declarations->businessDefinitions() as $definition) {
            $contributions->businessDefinition($definition);
        }
        $summaryView = $container->get(self::SUMMARY_VIEW);
        if (!$summaryView instanceof InspectionSummaryViewHandler) {
            throw new LogicException('The asset-inspection custom-view handler is unavailable.');
        }
        foreach ($declarations->customBusinessViews() as $definition) {
            $contributions->customBusinessViewHandler($definition, $summaryView);
        }
        $this->administrator($contributions, $container);
        $this->portal($contributions, $container);
        $this->integration($contributions, $container);
    }

    /**
     * Register the graphical administrator workspace, navigation, view, and guarded route.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Owner-bound contribution sink.
     * @param   ExtensionContainer              $container      Owner-scoped factory source.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function administrator(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $declarations = Definitions::all();
        foreach ($declarations->workspaces() as $definition) {
            $contributions->administratorWorkspace($definition);
        }
        foreach ($declarations->navigation() as $definition) {
            $contributions->administratorNavigation($definition);
        }
        foreach ($declarations->views() as $definition) {
            $contributions->administratorView($definition);
        }
        $factory = $container->get(self::ADMINISTRATOR_FACTORY);
        if (!$factory instanceof AdministratorRouteHandlerFactory) {
            throw new LogicException('The asset-inspection administrator handler factory is unavailable.');
        }
        foreach ($declarations->routes() as $definition) {
            $contributions->administratorRoute($definition, $factory);
        }
    }

    /**
     * Register the capability-gated portal view that remains invisible until an operator grants access.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Owner-bound contribution sink.
     * @param   ExtensionContainer              $container      Owner-scoped factory source.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function portal(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $declarations = Definitions::all();
        foreach ($declarations->portalWorkspaces() as $definition) {
            $contributions->portalWorkspace($definition);
        }
        foreach ($declarations->portalNavigation() as $definition) {
            $contributions->portalNavigation($definition);
        }
        foreach ($declarations->portalTemplates() as $definition) {
            $contributions->portalTemplate($definition);
        }
        $factory = $container->get(self::PORTAL_FACTORY);
        if (!$factory instanceof PortalRouteHandlerFactory) {
            throw new LogicException('The asset-inspection portal handler factory is unavailable.');
        }
        foreach ($declarations->portalRoutes() as $definition) {
            $contributions->portalRoute($definition, $factory);
        }
    }

    /**
     * Register the executable listener, durable consumer, job, schedule, projection, and report graph.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Owner-bound contribution sink.
     * @param   ExtensionContainer              $container      Owner-scoped diagnostic service source.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function integration(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $ledger = $container->get(self::LEDGER);
        if (!$ledger instanceof IntegrationLedger) {
            throw new LogicException('The asset-inspection integration ledger is unavailable.');
        }
        foreach (Definitions::all()->eventSchemas() as $definition) {
            $contributions->eventSchema($definition);
        }
        $contributions->queue(Definitions::queue());
        $listener = Definitions::listener();
        $contributions->domainListener($listener, new InspectionMutationListener($listener, $ledger));
        $consumer = Definitions::consumer();
        $contributions->eventConsumer($consumer, new InspectionMutationConsumer($consumer, $ledger));
        $contributions->jobHandler(Definitions::job(), new ReviewOverdueInspectionJob($ledger));
        $contributions->schedule(Definitions::schedule());
        $projection = Definitions::projection();
        $contributions->projection($projection, new InspectionActivityProjectionBuilder($projection));
        $contributions->report(Definitions::report());
    }

    /**
     * Complete boot without direct side effects; trusted registries own executable lifecycle.
     *
     * @param   ExtensionContainer  $container  Restricted owner-scoped services.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function boot(ExtensionContainer $container): void
    {
    }

    /**
     * Leave legacy routes empty because typed administrator and portal contributions own delivery.
     *
     * @param   ExtensionRouteRegistrar  $routes  Owner-bound legacy route registrar.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function registerRoutes(ExtensionRouteRegistrar $routes): void
    {
    }
}
