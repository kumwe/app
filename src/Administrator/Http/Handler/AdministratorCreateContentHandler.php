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

/**
 * Applies the create submission of the administrator content editor and sends the operator to the entry.
 *
 * `POST /administrator/content` is the only route that authors a new entry from the administrator, and
 * it accepts both editor shapes: the schema-generated `field__` inputs, which are mapped back through
 * the content type's schema, and the older hand-written `data` JSON field. Choosing between them here
 * is what lets the two editors share one route and one validation path. The handler renders nothing —
 * it always answers with a redirect to the new entry's edit screen, so the operator lands on what they
 * just created and a refresh cannot author a second copy.
 *
 * @since  2.0.0
 */
final readonly class AdministratorCreateContentHandler implements RequestHandlerInterface
{
    /**
     * Wire the create route to the content service and the optional schema-aware form reader.
     *
     * @param  ContentService          $content  Validates, stores and audits the entry being created.
     * @param  ?ContentModelService    $models   Resolves the content type generated fields are mapped
     *         against; null keeps every submission on the raw `data` field.
     * @param  ?ContentFormDataMapper  $mapper   Reads the generated fields; one is built per call when null.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private ?ContentModelService $models = null,
        private ?ContentFormDataMapper $mapper = null,
    ) {
    }

    /**
     * Create the entry the submitted form describes and redirect to its edit screen.
     *
     * The body is read through the content type's schema when the form carries `field__` inputs and a
     * model service is wired, and from the raw `data` JSON field otherwise. A `content_type` that is
     * absent, blank or not a string falls back to the built-in page type, which is how the older form
     * still authors a page without naming one.
     *
     * @param   ServerRequestInterface  $request  Administrator request, already authenticated and CSRF-checked.
     *
     * @return  ResponseInterface  A 303 redirect to the new entry's `/administrator/content/{id}/edit` screen.
     *
     * @throws  \InvalidArgumentException  When `title` or `slug` is missing, or a submitted value does not parse.
     * @throws  \DateMalformedStringException  When `publish_at` or `unpublish_at` is not a readable date.
     * @throws  \Kumwe\CMS\Content\Application\ContentModelNotFound  When the named content type is not published.
     * @throws  \Kumwe\CMS\Content\Domain\InvalidContentData  When the body does not satisfy the type's schema.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not create content, or
     *          may not read the content type it named.
     *
     * @since   2.0.0
     */
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
