<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Content\Application\ContentService;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorCreateContentHandler implements RequestHandlerInterface
{
    public function __construct(private ContentService $content)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $entry = $this->content->create(
            AdministratorRequest::session($request)->principal->subject(),
            AdministratorRequest::required($form, 'title'),
            AdministratorRequest::required($form, 'slug'),
            AdministratorRequest::contentData($form),
            AdministratorRequest::publicationWindow($form),
        );

        return new RedirectResponse('/administrator/content/' . $entry->entry->id() . '/edit', 303);
    }
}
