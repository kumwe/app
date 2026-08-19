<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Delivery\Administrator;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\BusinessDefinition\Administrator\BusinessDefinitionFormMapper;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionNotFound;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\App\BusinessDefinition\Application\DefinitionDraft;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the administrator business-definitions screen and the form posts it makes back to itself.
 *
 * One route carries the whole editing loop: a GET renders the catalog beside the selected definition's
 * draft, published head, version history and compatibility preview; `?export=` streams a definition as a
 * JSON download; and a POST dispatches one form action onto `BusinessDefinitionService` before answering a
 * redirect, so a refresh cannot repeat a publication. No policy is decided here — authorization, schema
 * validation, compatibility analysis and auditing all belong to the service, and this handler only turns a
 * flat HTML form into the call that carries them out.
 *
 * @since  2.0.0
 */
final readonly class BusinessDefinitionsHandler implements RequestHandlerInterface
{
    /**
     * Bounded, URL-addressable tasks exposed by the definition workspace.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const TABS = [
        'identity' => 'Identity',
        'fields' => 'Fields',
        'relationships' => 'Relationships',
        'delivery' => 'Views and actions',
        'workflow' => 'Workflow',
        'publication' => 'Publication',
        'history' => 'History',
    ];

    /**
     * Wire the screen to the application service and the collaborators that shape its two responses.
     *
     * @param  BusinessDefinitionService     $definitions  Service every form action is dispatched to.
     * @param  BusinessDefinitionFormMapper  $mapper       Builds a definition from the posted editor form.
     * @param  FieldTypeRegistry             $fieldTypes   Active field types the editor offers for new fields.
     * @param  AdministratorRenderer         $renderer     Renders the `business-definitions` template.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionService $definitions,
        private BusinessDefinitionFormMapper $mapper,
        private FieldTypeRegistry $fieldTypes,
        private AdministratorRenderer $renderer,
    ) {
    }

    /**
     * Dispatch one administrator request: a form action, a JSON export, or the screen itself.
     *
     * A POST runs its action and answers 303 back to the screen with the definition and bounded contextual
     * tab it acted on in the query string. A GET carrying `export` answers the canonical JSON — the draft
     * when one exists, otherwise the published head — as an attachment tagged with the definition checksum. Otherwise
     * the screen renders, defaulting to the first catalog entry; a selection that has since disappeared falls
     * back to no selection rather than failing the whole page.
     *
     * @param   ServerRequestInterface  $request  Administrator request carrying the session and context.
     *
     * @return  ResponseInterface  A 303 redirect, a JSON download, or the rendered screen.
     *
     * @throws  BusinessDefinitionNotFound  When `export` names a definition the catalog does not hold.
     * @throws  \InvalidArgumentException  When the action, the revision, or the uploaded file is unusable.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdministratorRequest::context($request);
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $action = AdministratorRequest::required($form, 'action');
            $activeTab = $this->activeTab($form['return_tab'] ?? null);
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
            $target = ['tab' => $activeTab];
            if ($identifier !== '') {
                $target['definition'] = $identifier;
            }

            return new RedirectResponse(
                '/administrator/business-definitions?' . http_build_query($target),
                303,
            );
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
        $activeTab = $this->activeTab($query['tab'] ?? null);
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
            'active_tab' => $activeTab,
            'workspace_tabs' => $this->tabs($selected, $creating),
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Resolve a tab identifier through the workspace's fixed vocabulary.
     *
     * Unknown and non-string input falls back to identity. This prevents arbitrary query or form input
     * becoming markup while keeping copied and stale links useful after the tab set evolves.
     *
     * @param   mixed  $candidate  Query-string or posted return-tab value.
     *
     * @return  string  A key from {@see self::TABS}.
     *
     * @since   2.0.0
     */
    private function activeTab(mixed $candidate): string
    {
        return is_string($candidate) && array_key_exists($candidate, self::TABS)
            ? $candidate
            : 'identity';
    }

    /**
     * Build contextual tab links without losing the selected definition or new-definition state.
     *
     * @param   string  $selected  Selected definition identifier, or an empty string.
     * @param   bool    $creating  Whether the editor is creating a definition.
     *
     * @return  list<array{id: string, label: string, href: string}>  Tabs ready for the KIS component.
     *
     * @since   2.0.0
     */
    private function tabs(string $selected, bool $creating): array
    {
        $context = [];
        if ($creating) {
            $context['new'] = '1';
        } elseif ($selected !== '') {
            $context['definition'] = $selected;
        }

        $tabs = [];
        foreach (self::TABS as $identifier => $label) {
            $tabs[] = [
                'id' => $identifier,
                'label' => $label,
                'href' => '/administrator/business-definitions?' . http_build_query([
                    ...$context,
                    'tab' => $identifier,
                ]),
            ];
        }

        return $tabs;
    }

    /**
     * Find a catalog row by either its identifier or its handle.
     *
     * The screen links definitions by handle while the service keys them by id, so both spellings are
     * resolved here against the catalog already read for this request rather than by a second query.
     *
     * @param   list<DefinitionCatalogEntry>  $catalog     Catalog read at the start of this request.
     * @param   string                        $identifier  Definition id or handle to look for.
     *
     * @return  ?DefinitionCatalogEntry  Null when neither spelling matches a row.
     *
     * @since   2.0.0
     */
    private function entry(array $catalog, string $identifier): ?DefinitionCatalogEntry
    {
        foreach ($catalog as $entry) {
            if ($entry->id === $identifier || $entry->handle === $identifier) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Flatten a catalog row into the document the template's definition list iterates.
     *
     * @param   DefinitionCatalogEntry  $entry  Row to project.
     *
     * @return  array<string, mixed>  Identity, ownership and revision counters; no definition body.
     *
     * @since   2.0.0
     */
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

    /**
     * Flatten a stored version into the row the template's history table lists.
     *
     * @param   DefinitionVersionRecord  $record  Version to project.
     *
     * @return  array<string, mixed>  Version metadata and its compatibility plan, without the definition.
     *
     * @since   2.0.0
     */
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

    /**
     * Read the draft revision the editor claims to be writing on top of.
     *
     * A blank field is not zero: it means the form made no claim at all, which the service accepts only for
     * a definition that does not exist yet. An existing definition posted without a revision is rejected
     * downstream as a conflict rather than overwriting a concurrent edit.
     *
     * @param   array<string, string>  $form  Flattened administrator form.
     *
     * @return  ?int  The expected draft revision, or null when the field was left blank.
     *
     * @throws  \InvalidArgumentException  When the field holds anything but decimal digits.
     *
     * @since   2.0.0
     */
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

    /**
     * Read the uploaded file as the JSON string the import action hands to the service.
     *
     * The upload must have arrived intact, with a known size no greater than 1 MiB; the bytes themselves are
     * not inspected here, since rejecting a document that is not a business definition is the service's job.
     *
     * @param   ServerRequestInterface  $request  Request carrying the `definition_file` upload.
     *
     * @return  string  The uploaded bytes, exactly as received.
     *
     * @throws  \InvalidArgumentException  When the upload is absent, failed, oversized or of unknown size.
     *
     * @since   2.0.0
     */
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
