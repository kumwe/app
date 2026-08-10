<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Delivery\Portal;

use @@PHP_NAMESPACE@@\Application\OverviewService;
use Kumwe\CMS\Portal\Http\PortalRequest;
use Kumwe\CMS\Portal\Presentation\PortalContributionRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adapts an authorized portal request to the component overview.
 *
 * @since  2.0.0
 */
final readonly class OverviewHandler implements RequestHandlerInterface
{
    /**
     * Bind request adaptation to the application service and owner-bound renderer.
     *
     * @param  OverviewService             $overview  Transport-neutral component service.
     * @param  PortalContributionRenderer  $renderer  Owner-and-template-bound portal renderer.
     *
     * @since  2.0.0
     */
    public function __construct(private OverviewService $overview, private PortalContributionRenderer $renderer)
    {
    }

    /**
     * Render the overview from an already authenticated and authorized portal request.
     *
     * @param   ServerRequestInterface  $request  Request carrying portal context and session attributes.
     *
     * @return  ResponseInterface  Non-cacheable HTML response rendered inside the portal shell.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $model = $this->overview->overview(PortalRequest::context($request));

        return new HtmlResponse($this->renderer->render(
            $model + ['capabilities' => PortalRequest::capabilityMap($request)],
            $session,
        ), 200, ['Cache-Control' => 'no-store']);
    }
}
