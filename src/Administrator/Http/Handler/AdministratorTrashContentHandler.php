<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Content\Application\ContentService;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorTrashContentHandler implements RequestHandlerInterface
{
    public function __construct(private ContentService $content)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $this->content->trash(
            AdministratorRequest::session($request)->principal->subject(),
            AdministratorRequest::routeId($request),
            AdministratorRequest::positiveInteger($form, 'version'),
        );

        return new RedirectResponse('/administrator', 303);
    }
}
