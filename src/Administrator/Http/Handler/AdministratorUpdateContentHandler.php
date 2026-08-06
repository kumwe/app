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

final readonly class AdministratorUpdateContentHandler implements RequestHandlerInterface
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
        $id = AdministratorRequest::routeId($request);
        $context = AdministratorRequest::context($request);
        $body = AdministratorRequest::parsedBody($request);
        $mapper = $this->mapper ?? new ContentFormDataMapper();
        $data = AdministratorRequest::contentData($form);
        if ($mapper->containsGeneratedFields($body) && $this->models !== null) {
            $record = $this->content->get($context, $id);
            $definition = $this->models->contentType(
                $context,
                $record->contentTypeId,
                $record->contentTypeVersion,
            );
            $data = $mapper->map($definition, $body);
        }
        $this->content->update(
            $context,
            $id,
            AdministratorRequest::positiveInteger($form, 'version'),
            AdministratorRequest::required($form, 'title'),
            AdministratorRequest::required($form, 'slug'),
            $data,
            AdministratorRequest::publicationWindow($form),
        );

        return new RedirectResponse('/administrator/content/' . $id . '/edit', 303);
    }
}
