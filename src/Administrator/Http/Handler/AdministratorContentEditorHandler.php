<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Content\ContentFormPresenter;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Kumwe\CMS\Media\Application\MediaAsset;
use Kumwe\CMS\Media\Application\MediaService;
use Kumwe\CMS\Site\Application\PublicPageLocator;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the administrator content editor, for both a brand new entry and an existing one.
 *
 * The same screen backs `/administrator/content/new` and `/administrator/content/{id}/edit`; the
 * presence of an `id` route attribute is what decides which. It is read-only — the rendered form
 * posts to the separate create and update handlers — so this class exists purely to assemble
 * everything the form needs in one place: the stored entry, the content type it is pinned to, the
 * workflow governing it, the field descriptors derived from that type's schema, and the media
 * library to pick from. Definitions are read at the versions the entry pinned rather than at head,
 * which is what keeps an older entry editable after its type or workflow was republished.
 *
 * @since  2.0.0
 */
final readonly class AdministratorContentEditorHandler implements RequestHandlerInterface
{
    /**
     * Wire the editor to the services supplying the entry and the vocabulary it is edited against.
     *
     * @param  ContentService         $content      Loads the entry being edited, trashed ones included.
     * @param  ContentModelService    $models       Supplies the pinned content type and workflow versions.
     * @param  AdministratorRenderer  $renderer     Renders the `content-form` template.
     * @param  ?ContentFormPresenter  $form         Turns a schema into field descriptors; null builds a default.
     * @param  ?MediaService          $media        Backs the media picker; null renders the form without one.
     * @param  ?PublicPageLocator     $publicPages  Resolves the entry's public URL; null omits the link.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private ContentModelService $models,
        private AdministratorRenderer $renderer,
        private ?ContentFormPresenter $form = null,
        private ?MediaService $media = null,
        private ?PublicPageLocator $publicPages = null,
    ) {
    }

    /**
     * Assemble and render the editor for a new entry or for the entry the route names.
     *
     * An existing entry is loaded with trashed entries included, so an operator about to restore one
     * can still see it. Stored entry data is filtered down to string keys before it reaches the
     * presenter, because a numeric key could never correspond to a schema field.
     *
     * @param   ServerRequestInterface  $request  Administrator request; an `id` attribute selects edit mode.
     *
     * @return  ResponseInterface  The rendered editor, marked `no-store` because it carries a CSRF token.
     *
     * @throws  \RuntimeException  When the stored entry's pinned type or workflow reference is unusable.
     * @throws  \Kumwe\CMS\Content\Application\ContentNotFound  When the route names an entry out of reach.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $id = $request->getAttribute('id');
        $entry = null;

        if (is_string($id) && $id !== '') {
            $record = $this->content->get(AdministratorRequest::context($request), $id, true);
            $entry = $record->toArray() + ['public_url' => $this->publicPages?->publicPathFor($record)];
        }

        $context = AdministratorRequest::context($request);
        $definitions = $this->models->contentTypes($context);
        $types = array_map(static fn (ContentTypeDefinition $type): array => $type->toArray(), $definitions);
        $selectedType = $this->selectedType($request, $definitions, $entry);
        $workflow = null;
        if (is_array($entry)) {
            $workflowId = $entry['workflow_id'] ?? null;
            $workflowVersion = $entry['workflow_version'] ?? null;
            if (!is_string($workflowId) || !is_int($workflowVersion)) {
                throw new \RuntimeException('The stored content workflow reference is invalid.');
            }
            $workflow = $this->models->workflow(
                $context,
                $workflowId,
                $workflowVersion,
            )->toArray();
        }
        $values = [];
        $storedData = $entry['data'] ?? null;
        if (is_array($storedData)) {
            foreach ($storedData as $key => $value) {
                if (is_string($key)) {
                    $values[$key] = $value;
                }
            }
        }

        return new HtmlResponse($this->renderer->render('content-form', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'entry' => $entry,
            'content_types' => $types,
            'content_type' => $selectedType->toArray(),
            'fields' => ($this->form ?? new ContentFormPresenter())->fields(
                $selectedType,
                $values,
            ),
            'workflow' => $workflow,
            'media_assets' => $this->media === null ? [] : array_map(
                static fn (MediaAsset $asset): array => $asset->toArray(),
                $this->media->browse($context, perPage: 48)->items,
            ),
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Decide which content type the editor builds its fields from.
     *
     * An existing entry always wins, and is resolved at the version it pinned, so opening an old
     * entry never quietly migrates it onto a newer schema. For a new entry the `content_type` query
     * parameter selects by UUID or handle — that is how the "new entry of this type" links work.
     * When nothing was asked for, the core Page type is the ambient default so seeding further
     * layout types never changes what an unqualified `/administrator/content/new` creates; the
     * first defined type remains the fallback only on sites that removed the core type.
     *
     * @param   ServerRequestInterface       $request      Request whose query string may name a type.
     * @param   list<ContentTypeDefinition>  $definitions  Head versions available to the acting site.
     * @param   array<string, mixed>|null    $entry        Stored entry being edited, or null when creating.
     *
     * @return  ContentTypeDefinition  The definition whose schema the rendered form is built from.
     *
     * @throws  \RuntimeException  When the entry's pinned type reference is unusable, or no type is defined.
     *
     * @since   2.0.0
     */
    private function selectedType(
        ServerRequestInterface $request,
        array $definitions,
        ?array $entry,
    ): ContentTypeDefinition {
        if ($entry !== null) {
            $id = $entry['content_type_id'] ?? null;
            $version = $entry['content_type_version'] ?? null;
            if (!is_string($id) || !is_int($version)) {
                throw new \RuntimeException('The stored content type reference is invalid.');
            }
            return $this->models->contentType(AdministratorRequest::context($request), $id, $version);
        }
        if ($definitions === []) {
            throw new \RuntimeException('At least one content type is required before content can be created.');
        }
        $requested = $request->getQueryParams()['content_type'] ?? '';
        if (is_string($requested) && $requested !== '') {
            foreach ($definitions as $definition) {
                if ($definition->id === $requested || $definition->handle === $requested) {
                    return $definition;
                }
            }
        }
        foreach ($definitions as $definition) {
            if ($definition->id === ContentService::CORE_PAGE_TYPE_ID) {
                return $definition;
            }
        }

        return $definitions[0];
    }
}
