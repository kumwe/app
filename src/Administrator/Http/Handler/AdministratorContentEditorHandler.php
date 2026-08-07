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

final readonly class AdministratorContentEditorHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentService $content,
        private ContentModelService $models,
        private AdministratorRenderer $renderer,
        private ?ContentFormPresenter $form = null,
        private ?MediaService $media = null,
        private ?PublicPageLocator $publicPages = null,
    ) {
    }

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
     * @param list<ContentTypeDefinition> $definitions
     * @param array<string, mixed>|null $entry
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

        return $definitions[0];
    }
}
