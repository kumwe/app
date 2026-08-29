<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection;

use LogicException;
use Kumwe\Extension\Spi\Binding\ExtensionBindingProvider;
use Kumwe\Extension\Spi\Binding\ExtensionBindingRegistrar;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteHandlerFactory;
use Kumwe\Extension\Spi\Binding\Http\PortalRouteHandlerFactory;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReader;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;
use KumweExample\AssetInspection\Application\InspectionOverviewService;
use KumweExample\AssetInspection\Application\InspectionSummaryViewHandler;
use KumweExample\AssetInspection\Delivery\Administrator\InspectionOverviewHandlerFactory;
use KumweExample\AssetInspection\Delivery\Portal\InspectionStatusHandlerFactory;
use KumweExample\AssetInspection\Integration\InspectionActivityProjectionBuilder;
use KumweExample\AssetInspection\Integration\InspectionMutationConsumer;
use KumweExample\AssetInspection\Integration\InspectionMutationListener;
use KumweExample\AssetInspection\Integration\IntegrationLedger;
use KumweExample\AssetInspection\Integration\ReviewOverdueInspectionJob;

/**
 * Registers services and binds executable behavior to identifiers in the signed manifest.
 *
 * The provider does not reconstruct declarations. The SDK manifest graph is the sole authority for
 * capabilities, policies, business definitions, navigation, routes, integration contracts and reports.
 *
 * @since  2.0.0
 */
final class Provider implements ExtensionBindingProvider
{
    /**
     * Owner-scoped diagnostic ledger service.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string LEDGER = 'extension.kumwe.asset-inspection-example.integration-ledger';

    /**
     * Owner-scoped overview application service.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string OVERVIEW = 'extension.kumwe.asset-inspection-example.overview';

    /**
     * Owner-scoped custom-view handler.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SUMMARY_VIEW = 'extension.kumwe.asset-inspection-example.summary-view';

    /**
     * Owner-scoped administrator handler factory.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ADMINISTRATOR_FACTORY = 'extension.kumwe.asset-inspection-example.administrator-factory';

    /**
     * Owner-scoped portal handler factory.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PORTAL_FACTORY = 'extension.kumwe.asset-inspection-example.portal-factory';

    /**
     * Register owner-scoped services through SDK ports only.
     *
     * @param   ExtensionContainer  $container  Restricted host service surface.
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
            self::OVERVIEW,
            static function (ExtensionContainer $container): InspectionOverviewService {
                $ledger = $container->get(self::LEDGER);
                if (!$ledger instanceof IntegrationLedger) {
                    throw new LogicException('The asset-inspection integration ledger is unavailable.');
                }

                return new InspectionOverviewService($ledger);
            },
        );
        $container->share(
            self::SUMMARY_VIEW,
            static function (ExtensionContainer $container): InspectionSummaryViewHandler {
                $records = $container->get(BusinessRecordReader::class);
                if (!$records instanceof BusinessRecordReader) {
                    throw new LogicException('The asset-inspection record reader is unavailable.');
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
     * Bind every executable identifier required by the canonical SDK manifest graph.
     *
     * @param   ExtensionBindingRegistrar  $bindings   Owner-and-manifest-scoped executable sink.
     * @param   ExtensionContainer         $container  Restricted owner-scoped services.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function bind(ExtensionBindingRegistrar $bindings, ExtensionContainer $container): void
    {
        $ledger = $container->get(self::LEDGER);
        $summary = $container->get(self::SUMMARY_VIEW);
        $administrator = $container->get(self::ADMINISTRATOR_FACTORY);
        $portal = $container->get(self::PORTAL_FACTORY);
        if (
            !$ledger instanceof IntegrationLedger
            || !$summary instanceof InspectionSummaryViewHandler
            || !$administrator instanceof AdministratorRouteHandlerFactory
            || !$portal instanceof PortalRouteHandlerFactory
        ) {
            throw new LogicException('The asset-inspection executable services are unavailable.');
        }

        $bindings->customBusinessViewHandler(
            'kumwe.asset-inspection-example.views.inspection-risk-summary',
            $summary,
        );
        $bindings->administratorRoute('kumwe.asset-inspection-example.administrator.index', $administrator);
        $bindings->portalRoute('kumwe.asset-inspection-example.portal.status', $portal);
        $bindings->domainListener(
            'kumwe.asset-inspection-example.inspection-mutation-validator',
            new InspectionMutationListener($ledger),
        );
        $bindings->eventConsumer(
            'kumwe.asset-inspection-example.inspection-mutation-indexer',
            new InspectionMutationConsumer($ledger),
        );
        $bindings->jobHandler(
            'kumwe.asset-inspection-example.review-overdue',
            new ReviewOverdueInspectionJob($ledger),
        );
        $bindings->projection(
            'kumwe.asset-inspection-example.inspection-activity',
            new InspectionActivityProjectionBuilder(),
        );
    }
}
