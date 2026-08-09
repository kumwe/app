<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Http\Handler;

use Kumwe\CMS\Portal\Http\PortalRequest;
use Kumwe\CMS\Portal\Presentation\PortalRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders the ordinary-user portal landing page in its distinct shell.
 *
 * @since  2.0.0
 */
final readonly class PortalHomeHandler implements RequestHandlerInterface
{
    /**
     * Bind the home page to the portal renderer.
     *
     * @param  PortalRenderer  $renderer  Distinct portal shell renderer.
     *
     * @since  2.0.0
     */
    public function __construct(private PortalRenderer $renderer)
    {
    }

    /**
     * Render the home page from the resolved session only.
     *
     * @param   ServerRequestInterface  $request  Authenticated portal request.
     *
     * @return  ResponseInterface  No-store HTML portal page.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = PortalRequest::session($request);

        return new HtmlResponse(
            $this->renderer->render('home', ['active_navigation' => 'core.portal-home'], $session),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
