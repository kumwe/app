<?php

declare(strict_types=1);

namespace KumweExample\Announcements\Delivery;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use KumweExample\Announcements\Application\AnnouncementService;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AnnouncementsPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private AnnouncementService $announcements,
        private AdministratorRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $view = $this->announcements->dashboard(AdministratorRequest::context($request));
        return new HtmlResponse($this->renderer->renderExtension(
            'kumwe/announcements-example',
            'kumwe.announcements-example.index',
            $view + [
                'csrf' => $session->csrfToken,
                'capabilities' => AdministratorRequest::capabilityMap($request),
                'active_navigation' => 'kumwe.announcements-example.navigation',
            ],
        ), 200, ['Cache-Control' => 'no-store']);
    }
}
