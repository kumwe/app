<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Content\ContentFormDataMapper;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Application\ContentService;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorCreateContentHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentService $content,
        private ?ContentModelService $models = null,
        private ?ContentFormDataMapper $mapper = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $contentType = $form['content_type'] ?? ContentService::CORE_PAGE_TYPE_ID;
        if (!is_string($contentType) || trim($contentType) === '') {
            $contentType = ContentService::CORE_PAGE_TYPE_ID;
        }
        $context = AdministratorRequest::context($request);
        $body = AdministratorRequest::parsedBody($request);
        $mapper = $this->mapper ?? new ContentFormDataMapper();
        $data = $mapper->containsGeneratedFields($body) && $this->models !== null
            ? $mapper->map($this->models->contentType($context, $contentType), $body)
            : AdministratorRequest::contentData($form);
        $entry = $this->content->create(
            $context,
            AdministratorRequest::required($form, 'title'),
            AdministratorRequest::required($form, 'slug'),
            $data,
            AdministratorRequest::publicationWindow($form),
            $contentType,
        );

        return new RedirectResponse('/administrator/content/' . $entry->entry->id() . '/edit', 303);
    }
}
