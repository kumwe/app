<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Content\Application\ContentService;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorTransitionContentHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentService $content,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $id = AdministratorRequest::routeId($request);
        $this->content->transition(
            AdministratorRequest::context($request),
            $id,
            AdministratorRequest::positiveInteger($form, 'version'),
            AdministratorRequest::required($form, 'status'),
        );

        return new RedirectResponse('/administrator/content/' . $id . '/edit', 303);
    }
}
