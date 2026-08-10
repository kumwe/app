<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Delivery\Administrator;

use @@PHP_NAMESPACE@@\Application\OverviewService;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adapts an authorized administrator request to the component overview.
 *
 * @since  2.0.0
 */
final readonly class OverviewHandler implements RequestHandlerInterface
{
    /**
     * Bind request adaptation to the application service and shell renderer.
     *
     * @param  OverviewService        $overview  Transport-neutral component service.
     * @param  AdministratorRenderer  $renderer  Owner-bound administrator renderer.
     *
     * @since  2.0.0
     */
    public function __construct(private OverviewService $overview, private AdministratorRenderer $renderer)
    {
    }

    /**
     * Render the overview from an already authenticated and authorized administrator request.
     *
     * @param   ServerRequestInterface  $request  Request carrying administrator context and session attributes.
     *
     * @return  ResponseInterface  Non-cacheable HTML response rendered inside the administrator shell.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $model = $this->overview->overview(AdministratorRequest::context($request));

        return new HtmlResponse($this->renderer->renderExtension(
            '@@EXTENSION_IDENTIFIER@@',
            '@@EXTENSION_DOTTED@@.administrator.index',
            $model + [
                'csrf' => $session->csrfToken,
                'capabilities' => AdministratorRequest::capabilityMap($request),
                'active_navigation' => '@@EXTENSION_DOTTED@@.navigation',
            ],
        ), 200, ['Cache-Control' => 'no-store']);
    }
}
