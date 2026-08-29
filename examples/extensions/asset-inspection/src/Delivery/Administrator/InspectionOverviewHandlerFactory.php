<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Delivery\Administrator;

use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteHandlerFactory;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteRenderer;
use KumweExample\AssetInspection\Application\InspectionOverviewService;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Builds the administrator handler with the renderer granted by its route registry.
 *
 * @since  2.0.0
 */
final readonly class InspectionOverviewHandlerFactory implements AdministratorRouteHandlerFactory
{
    /**
     * Bind handler construction to the transport-neutral overview service.
     *
     * @param  InspectionOverviewService  $overview  Shared proof use case.
     *
     * @since  2.0.0
     */
    public function __construct(private InspectionOverviewService $overview)
    {
    }

    /**
     * Create the contributed route handler with an isolated administrator renderer.
     *
     * @param   AdministratorRouteRenderer  $renderer  Route-bound renderer capability supplied by the host.
     *
     * @return  RequestHandlerInterface  Ready graphical route handler.
     *
     * @since   2.0.0
     */
    public function create(AdministratorRouteRenderer $renderer): RequestHandlerInterface
    {
        return new InspectionOverviewHandler($this->overview, $renderer);
    }
}
