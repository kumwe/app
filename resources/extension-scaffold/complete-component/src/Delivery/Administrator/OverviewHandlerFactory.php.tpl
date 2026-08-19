<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Delivery\Administrator;

use @@PHP_NAMESPACE@@\Application\OverviewService;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Extension\Contribution\AdministratorRouteHandlerFactory;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Builds the administrator handler with the renderer granted by the registry.
 *
 * @since  2.0.0
 */
final readonly class OverviewHandlerFactory implements AdministratorRouteHandlerFactory
{
    /**
     * Bind handler construction to the component application service.
     *
     * @param  OverviewService  $overview  Transport-neutral component service.
     *
     * @since  2.0.0
     */
    public function __construct(private OverviewService $overview)
    {
    }

    /**
     * Build a handler using the administrator renderer granted by the route registry.
     *
     * @param   AdministratorRenderer  $renderer  Owner-aware administrator shell renderer.
     *
     * @return  RequestHandlerInterface  Ready administrator overview handler.
     *
     * @since   2.0.0
     */
    public function create(AdministratorRenderer $renderer): RequestHandlerInterface
    {
        return new OverviewHandler($this->overview, $renderer);
    }
}
