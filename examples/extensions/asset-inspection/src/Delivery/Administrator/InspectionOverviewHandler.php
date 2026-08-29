<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Delivery\Administrator;

use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteRenderer;
use Kumwe\Extension\Spi\Http\ExtensionRequest;
use KumweExample\AssetInspection\Application\InspectionOverviewService;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders the authorized graphical administrator proof page.
 *
 * @since  2.0.0
 */
final readonly class InspectionOverviewHandler implements RequestHandlerInterface
{
    /**
     * Bind request adaptation to the shared application service and isolated renderer.
     *
     * @param  InspectionOverviewService  $overview  Transport-neutral proof use case.
     * @param  AdministratorRouteRenderer $renderer  Route-bound host renderer capability.
     *
     * @since  2.0.0
     */
    public function __construct(
        private InspectionOverviewService $overview,
        private AdministratorRouteRenderer $renderer,
    ) {
    }

    /**
     * Render a no-store response from an already authenticated and authorized request.
     *
     * @param   ServerRequestInterface  $request  Request carrying administrator session and context.
     *
     * @return  ResponseInterface  Isolated graphical proof page.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $model = $this->overview->administrator(ExtensionRequest::context($request));

        return new HtmlResponse($this->renderer->render($model, $request), 200, ['Cache-Control' => 'no-store']);
    }
}
