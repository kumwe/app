<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorDashboardHandler implements RequestHandlerInterface
{
    public function __construct(private ContentService $content, private AdministratorRenderer $renderer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);

        return new HtmlResponse($this->renderer->render('content-list', [
            'csrf' => $session->csrfToken,
            'entries' => array_map(
                static fn (ContentRecord $record): array => $record->toArray(),
                $this->content->list(200, true),
            ),
        ]), 200, ['Cache-Control' => 'no-store']);
    }
}
