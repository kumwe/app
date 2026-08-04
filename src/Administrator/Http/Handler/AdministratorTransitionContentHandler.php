<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorTransitionContentHandler implements RequestHandlerInterface
{
    public function __construct(private ContentService $content)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $id = AdministratorRequest::routeId($request);
        $this->content->transition(
            AdministratorRequest::session($request)->principal->subject(),
            $id,
            AdministratorRequest::positiveInteger($form, 'version'),
            ContentStatus::from(AdministratorRequest::required($form, 'status')),
        );

        return new RedirectResponse('/administrator/content/' . $id . '/edit', 303);
    }
}
