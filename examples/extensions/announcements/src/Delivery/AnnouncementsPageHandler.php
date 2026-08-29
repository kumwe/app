<?php

declare(strict_types=1);

namespace KumweExample\Announcements\Delivery;

use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteRenderer;
use Kumwe\Extension\Spi\Http\ExtensionRequest;
use KumweExample\Announcements\Application\AnnouncementService;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AnnouncementsPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private AnnouncementService $announcements,
        private AdministratorRouteRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->announcements->dashboard(ExtensionRequest::context($request));
        return new HtmlResponse($this->renderer->render($view, $request), 200, ['Cache-Control' => 'no-store']);
    }
}
