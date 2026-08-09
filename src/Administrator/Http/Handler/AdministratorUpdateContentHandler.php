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
 * Saves an edit to one existing content entry from the administrator editor.
 *
 * The editor posts one of two body shapes, and reconciling them is this handler's real job: a raw
 * JSON `data` field for the generic editor, or the `field__` inputs a content type's generated form
 * renders. When generated inputs are present it loads the entry first, so the values are mapped
 * against the exact content type version the entry was authored against rather than the current head
 * — which is what lets an entry written before a stricter schema still be edited. Mounted on
 * `POST /administrator/content/{id}` behind the CSRF middleware and the `content.update` capability.
 *
 * @since  2.0.0
 */
final readonly class AdministratorUpdateContentHandler implements RequestHandlerInterface
{
    /**
     * Wire the editor's save action to the services that map and store the revision.
     *
     * @param  ContentService          $content  Loads the entry and writes the new version.
     * @param  ?ContentModelService    $models   Resolves the entry's pinned content type; null keeps every save on
     *         the raw JSON path.
     * @param  ?ContentFormDataMapper  $mapper   Maps generated form inputs into entry data; a default instance is
     *         built when null.
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
     * Apply the submitted revision to the entry named by the route and return to its editor.
     *
     * The `data` field is decoded either way, so a malformed JSON body is rejected even on a request
     * that would have been mapped from generated inputs; the mapped result then replaces it. Mapping
     * only happens when a content model service is wired and the body actually carries `field__`
     * inputs, and it costs one extra read of the entry — which is what pins the mapping to the
     * content type version the entry was authored against.
     *
     * @param   ServerRequestInterface  $request  Administrator POST carrying the editor's fields, `{id}` in the
     *          route.
     *
     * @return  ResponseInterface  A 303 redirect to the entry's edit screen.
     *
     * @throws  \InvalidArgumentException  When the route carries no identifier, a required field is missing, the
     *          JSON body is not an object, or a generated field does not parse.
     * @throws  \DateMalformedStringException  When a publication window field is not a readable date and time.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When `content.update` is refused.
     * @throws  \Kumwe\CMS\Content\Application\ContentNotFound  When no entry matches within reach of the context.
     * @throws  \Kumwe\CMS\Content\Application\ContentModelNotFound  When the entry's pinned content type version is
     *          no longer published.
     * @throws  \Kumwe\CMS\Content\Domain\InvalidContentData  When the body does not satisfy the pinned schema.
     * @throws  \Kumwe\CMS\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
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
