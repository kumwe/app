<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Delivery\Administrator;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\BusinessDefinition\Administrator\BusinessDefinitionFormMapper;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionNotFound;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionDraft;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class BusinessDefinitionsHandler implements RequestHandlerInterface
{
    public function __construct(
        private BusinessDefinitionService $definitions,
        private BusinessDefinitionFormMapper $mapper,
        private FieldTypeRegistry $fieldTypes,
        private AdministratorRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdministratorRequest::context($request);
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $action = AdministratorRequest::required($form, 'action');
            $identifier = trim($form['id'] ?? '');
            if ($identifier === '') {
                $identifier = trim($form['handle'] ?? '');
            }
            $result = match ($action) {
                'save' => $this->definitions->saveDraft(
                    $context,
                    $this->mapper->definition($form, $context->site()),
                    $this->revision($form),
                ),
                'validate' => $this->definitions->validateDraft($context, $identifier),
                'compare' => $this->definitions->compareDraft($context, $identifier),
                'publish' => $this->definitions->publish(
                    $context,
                    $identifier,
                    AdministratorRequest::positiveInteger($form, 'revision'),
                    ($form['confirmed'] ?? '') === '1',
                ),
                'supersede' => $this->definitions->supersede(
                    $context,
                    $identifier,
                    AdministratorRequest::positiveInteger($form, 'version'),
                ),
                'deprecate' => $this->definitions->deprecate(
                    $context,
                    $identifier,
                    AdministratorRequest::positiveInteger($form, 'version'),
                ),
                'reject' => $this->definitions->reject(
                    $context,
                    $identifier,
                    AdministratorRequest::positiveInteger($form, 'version'),
                ),
                'import' => $this->definitions->importJson($context, $this->import($request), $this->revision($form)),
                default => throw new \InvalidArgumentException('The business-definition action is unsupported.'),
            };
            if ($identifier === '' && $result instanceof DefinitionDraft) {
                $identifier = $result->definition->id;
            }
            $target = $identifier === '' ? '' : '?definition=' . rawurlencode($identifier);
            return new RedirectResponse('/administrator/business-definitions' . $target, 303);
        }

        $catalog = $this->definitions->catalog($context);
        $query = $request->getQueryParams();
        $export = is_string($query['export'] ?? null) ? trim($query['export']) : '';
        if ($export !== '') {
            $entry = $this->entry($catalog, $export) ?? throw new BusinessDefinitionNotFound($export);
            $record = $entry->draftRevision > 0
                ? $this->definitions->draft($context, $entry->id)
                : $this->definitions->published($context, $entry->id);
            $definition = $record->definition;
            return new JsonResponse($definition->toArray(), 200, [
                'Cache-Control' => 'no-store',
                'Content-Disposition' => sprintf(
                    'attachment; filename="%s.json"',
                    str_replace('.', '-', $definition->handle),
                ),
                'ETag' => '"' . $definition->checksum() . '"',
            ]);
        }
        $selected = is_string($query['definition'] ?? null) ? trim($query['definition']) : '';
        $creating = ($query['new'] ?? null) === '1';
        if (!$creating && $selected === '' && $catalog !== []) {
            $selected = $catalog[0]->id;
        }
        $draft = null;
        $published = null;
        $history = [];
        $plan = null;
        if ($selected !== '') {
            try {
                $entry = $this->entry($catalog, $selected);
                if ($entry?->draftRevision > 0) {
                    $draft = $this->definitions->draft($context, $selected);
                    $plan = $this->definitions->previewDraft($context, $selected);
                }
                if ($entry?->publishedVersion !== null) {
                    $published = $this->definitions->published($context, $selected);
                }
                $history = $this->definitions->history($context, $selected);
            } catch (BusinessDefinitionNotFound) {
                $selected = '';
            }
        }
        $definition = $draft->definition ?? $published?->definition;

        return new HtmlResponse($this->renderer->render('business-definitions', [
            'csrf' => AdministratorRequest::session($request)->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'catalog' => array_map($this->catalogDocument(...), $catalog),
            'selected' => $selected,
            'definition' => $definition?->toArray(),
            'draft' => $draft,
            'published' => $published,
            'history' => array_map($this->versionDocument(...), $history),
            'plan' => $plan?->toArray(),
            'field_types' => array_map(static fn ($type): array => $type->toArray(), $this->fieldTypes->all()),
            'site_namespace' => 'site.' . $context->site()->identifier() . '.',
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /** @param list<DefinitionCatalogEntry> $catalog */
    private function entry(array $catalog, string $identifier): ?DefinitionCatalogEntry
    {
        foreach ($catalog as $entry) {
            if ($entry->id === $identifier || $entry->handle === $identifier) {
                return $entry;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function catalogDocument(DefinitionCatalogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'handle' => $entry->handle,
            'owner' => $entry->owner->toArray(),
            'owner_active' => $entry->ownerActive,
            'draft_revision' => $entry->draftRevision,
            'published_version' => $entry->publishedVersion,
            'status' => $entry->status->value,
            'updated_at' => $entry->updatedAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function versionDocument(DefinitionVersionRecord $record): array
    {
        return [
            'version' => $record->definition->definitionVersion,
            'status' => $record->status->value,
            'checksum' => $record->definition->checksum(),
            'published_by' => $record->publishedBy,
            'published_at' => $record->publishedAt->format(DATE_ATOM),
            'plan' => $record->compatibility->toArray(),
        ];
    }

    /** @param array<string, string> $form */
    private function revision(array $form): ?int
    {
        $value = trim($form['revision'] ?? '');
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException('The draft revision is invalid.');
        }
        return (int) $value;
    }

    private function import(ServerRequestInterface $request): string
    {
        $upload = $request->getUploadedFiles()['definition_file'] ?? null;
        if (
            !$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK
            || $upload->getSize() === null || $upload->getSize() > 1_048_576
        ) {
            throw new \InvalidArgumentException('Select a business-definition JSON file no larger than 1 MiB.');
        }
        return (string) $upload->getStream();
    }
}
