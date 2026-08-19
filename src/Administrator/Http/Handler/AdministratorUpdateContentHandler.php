<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use Kumwe\App\Administrator\Content\ContentEditorSubmission;
use Kumwe\App\Administrator\Content\ContentFormDataMapper;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\InvalidContentData;
use Kumwe\App\Content\Domain\VersionConflict;
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
 * Neither of the two failures an editor can recover from reaches the error page. A body the schema
 * refuses, and a save that lost the optimistic-concurrency race, both return the operator to their own
 * editor with everything they typed still in the inputs; nothing is written on either path, so a
 * newer revision is never overwritten by a stale one.
 *
 * @since  2.0.0
 */
final readonly class AdministratorUpdateContentHandler implements RequestHandlerInterface
{
    /**
     * Wire the editor's save action to the services that map and store the revision.
     *
     * @param  ContentService                      $content  Loads the entry and writes the new version.
     * @param  ?ContentModelService                $models   Resolves the entry's pinned content type; null
     *         keeps every save on the raw JSON path.
     * @param  ?ContentFormDataMapper              $mapper   Maps generated form inputs into entry data; a
     *         default instance is built when null.
     * @param  ?AdministratorContentEditorHandler  $editor   Redraws the editor after a refused save; null
     *         lets the failure escape, which only suits a caller with no editor to return to.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private ?ContentModelService $models = null,
        private ?ContentFormDataMapper $mapper = null,
        private ?AdministratorContentEditorHandler $editor = null,
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
     * @return  ResponseInterface  A 303 redirect to the entry's edit screen, or that same editor redrawn at
     *          422 or 409 with the refused values still in its inputs.
     *
     * @throws  \InvalidArgumentException  When the route carries no identifier, a required field is missing, the
     *          JSON body is not an object, or a generated field does not parse.
     * @throws  \DateMalformedStringException  When a publication window field is not a readable date and time.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.update` is refused.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When no entry matches within reach of the context.
     * @throws  \Kumwe\App\Content\Application\ContentModelNotFound  When the entry's pinned content type version is
     *          no longer published.
     * @throws  \Kumwe\App\Content\Domain\InvalidContentData  When the body does not satisfy the pinned schema and no
     *          editor is wired to redraw the form.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first and no editor
     *          is wired to redraw the form.
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
        $contentType = $form['content_type'] ?? '';
        if ($mapper->containsGeneratedFields($body) && $this->models !== null) {
            $record = $this->content->get($context, $id);
            $definition = $this->models->contentType(
                $context,
                $record->contentTypeId,
                $record->contentTypeVersion,
            );
            $data = $mapper->map($definition, $body);
        }
        $version = AdministratorRequest::positiveInteger($form, 'version');
        try {
            $this->content->update(
                $context,
                $id,
                $version,
                AdministratorRequest::required($form, 'title'),
                AdministratorRequest::required($form, 'slug'),
                $data,
                AdministratorRequest::publicationWindow($form),
            );
        } catch (InvalidContentData $exception) {
            if ($this->editor === null) {
                throw $exception;
            }

            return $this->editor->render(
                $request,
                ContentEditorSubmission::fromForm($form, $data, $contentType)
                    ->rejectedBy($exception->violations),
                422,
            );
        } catch (VersionConflict $exception) {
            if ($this->editor === null) {
                throw $exception;
            }

            return $this->editor->render(
                $request,
                ContentEditorSubmission::fromForm($form, $data, $contentType)->conflictedAt($version),
                409,
            );
        }

        return new RedirectResponse('/administrator/content/' . $id . '/edit', 303);
    }
}
