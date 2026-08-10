<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Delivery\Administrator;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
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
     * @param  AdministratorRenderer      $renderer  Isolated extension view renderer.
     *
     * @since  2.0.0
     */
    public function __construct(
        private InspectionOverviewService $overview,
        private AdministratorRenderer $renderer,
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
        $session = AdministratorRequest::session($request);
        $model = $this->overview->administrator(AdministratorRequest::context($request));

        return new HtmlResponse($this->renderer->renderExtension(
            'kumwe/asset-inspection-example',
            'kumwe.asset-inspection-example.administrator.index',
            $model + [
                'csrf' => $session->csrfToken,
                'capabilities' => AdministratorRequest::capabilityMap($request),
                'active_navigation' => 'kumwe.asset-inspection-example.navigation',
            ],
        ), 200, ['Cache-Control' => 'no-store']);
    }
}
