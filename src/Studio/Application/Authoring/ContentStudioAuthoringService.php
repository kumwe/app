<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use InvalidArgumentException;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\Content\Application\ContentModelNotFound;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\Content\Application\ContentNotFound;
use Kumwe\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\Content\Application\IncompatibleDefinition;
use Kumwe\Content\Domain\ContentTypeDefinition;
use Kumwe\Content\Domain\InvalidContentData;
use Kumwe\Content\Domain\VersionConflict;
use Kumwe\App\Studio\Application\Composition\StudioCompositionLockMismatch;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Composition\StudioContentComposition;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Composition\StudioContentDefaultComposition;
use Kumwe\App\Studio\Application\Composition\StudioItemCompositionPolicy;
use Kumwe\App\Studio\Application\Composition\StudioPublishedBlueprintMismatch;
use Kumwe\App\Studio\Application\Composition\StudioPublishedModelMismatch;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Preview\StudioPublishedBlockRendererUnavailable;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\Content\Workflow\Domain\WorkflowDefinition;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Localization\Application\Translator;
use Kumwe\Producer\Canonical\CanonicalEncodingException;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use stdClass;

/**
 * PHP-authoritative implementation of Studio's seven contextual authoring operations for Content.
 *
 * Every operation re-derives its authority from the opaque host session and the opaque Content
 * authoring context behind it, reprojects live Content state through the existing services, and
 * compares what Studio echoes back byte for byte before any durable effect. Saves terminate in the
 * Content application services (entries, types, versions) and the composition service (Blueprints);
 * nothing here writes a table. Refusals are canonical Producer host errors.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringService
{
    /**
     * Renderer capabilities this deployment implements for Content compositions.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array RENDERERS = ['core.renderer/field', 'core.renderer/layout'];

    /**
     * Revision of a not-yet-created item inside a session.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DRAFT_ENTRY_REVISION = 'content-entry-draft';

    /**
     * Version and revision of a blank canvas Model before it becomes a Content type.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DRAFT_MODEL_VERSION = '0.0.1';

    /**
     * Revision of a blank canvas Model before it becomes a Content type.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DRAFT_MODEL_REVISION = 'content-type-draft';

    /**
     * Revision of a blank canvas Blueprint before it is adopted by a Content type.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DRAFT_BLUEPRINT_REVISION = 'content-blueprint-draft';

    /**
     * Consequence an author must accept before a breaking schema change is published.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string BREAKING_CHANGE = 'kumwe.app/breaking-schema-change';

    /**
     * Consequence noting that dependent items keep the current type version until migrated.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DEPENDENT_ENTRIES = 'kumwe.app/dependent-entries-remain';

    /**
     * Consequence noting that the current item adopts the accepted successor coordinates.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ITEM_ADOPTS = 'kumwe.app/item-adopts-successor';

    /**
     * Consequence noting that the layout is kept for this item only, beside its entry (App ADR 0025).
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ITEM_LAYOUT_KEPT = 'kumwe.app/item-layout-kept';

    /**
     * Consequence warning that a published item's new layout, its own or its type's again, appears on the public
     * site when it is saved.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ITEM_LAYOUT_LIVE = 'kumwe.app/item-layout-live';

    /**
     * Consequence noting that the item follows its content type's layout again.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ITEM_LAYOUT_INHERITED = 'kumwe.app/item-layout-inherited';

    /**
     * Consequence noting that the item's own layout becomes the layout of the type it saves.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ITEM_LAYOUT_PROMOTED = 'kumwe.app/item-layout-promoted';

    /**
     * Diagnostic noting that the item's own layout was made for another type version, so it is kept but unused.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ITEM_LAYOUT_DETACHED = 'kumwe.app/item-layout-detached';

    /**
     * Studio permission an item session must hold for Save item to keep the item's own layout.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string EDIT_BLUEPRINT = 'studio.permission/edit-blueprint';

    /**
     * Presentation states every contextual session may occupy.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array PRESENTATIONS = ['inline', 'minimized', 'maximized', 'fullscreen'];

    /**
     * Compose the authoritative Content, projection, composition and contract services.
     *
     * @param  ContentStudioAuthoringContextAuthority  $contexts         Opaque exact-target context authority.
     * @param  ContentService                          $content          Authorized Content entry service.
     * @param  ContentModelService                     $models           Authorized Content type service.
     * @param  ContentStudioProjector                  $projector        Canonical Content-to-Studio projection.
     * @param  StudioContentCompositionService         $compositions     Type-version Blueprint composition service.
     * @param  ContentProjectionBindingRepository      $bindings         Read-only binding and override projection.
     * @param  StudioPublishedTheme                    $theme            Exact published public-theme authority.
     * @param  StudioDocumentSchemaRegistry            $schemas          Producer's pinned schema interpreter.
     * @param ContentStudioAuthoringCatalog $catalog The one block catalog sessions lock and saves admit.
     * @param  Translator                              $translator       Interface-locale text the host hands Studio
     *         for its own labels and save consequences.
     * @param  StudioItemCompositionPolicy             $itemComposition  The App-wide item-composition policy the
     *         reusable type declares and every item layout read, plan and save obeys (App ADR 0025).
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentStudioAuthoringContextAuthority $contexts,
        private ContentService $content,
        private ContentModelService $models,
        private ContentStudioProjector $projector,
        private StudioContentCompositionService $compositions,
        private ContentProjectionBindingRepository $bindings,
        private StudioPublishedTheme $theme,
        private StudioDocumentSchemaRegistry $schemas,
        private ContentStudioAuthoringCatalog $catalog,
        private Translator $translator,
        private StudioItemCompositionPolicy $itemComposition = new StudioItemCompositionPolicy(),
    ) {
    }

    /**
     * Resolve the declared target for the session's exact resource and intent.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $request   Schema-valid `authoring-target` resolve request.
     *
     * @return  stdClass  Schema-valid `authoring-target` resolution.
     *
     * @since   2.0.0
     */
    public function resolveTarget(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $request,
    ): stdClass {
        return $this->catalog->consistently(
            fn (): stdClass => $this->resolveTargetOperation($context, $snapshot, $request),
        );
    }

    /**
     * Perform `resolveTarget` inside the one contribution decision its public entry opened.
     *
     * Resolve the declared target for the session's exact resource and intent.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $request   Schema-valid `authoring-target` resolve request.
     *
     * @return  stdClass  Schema-valid `authoring-target` resolution.
     *
     * @since   2.0.0
     */
    private function resolveTargetOperation(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $request,
    ): stdClass {
        $session = $this->session($context, $snapshot, true);
        $this->assertTargetAndContext($session, $request->targetId ?? null, $request->resourceContext ?? null);
        if (($request->intent ?? null) !== $session->target->intent->value) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/intent-mismatch');
        }
        $presentation = $request->requestedPresentation ?? 'inline';
        if (!is_string($presentation) || !in_array($presentation, self::PRESENTATIONS, true)) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/presentation-unavailable');
        }

        return $this->validated('authoring-target', 'resolution', (object) [
            'target' => $this->catalog->declaration(),
            'resourceContext' => $session->resourceContext(),
            'availableStarts' => $session->target->intent === StudioAuthoringIntent::Edit
                ? ['existing']
                : ['blank', 'from-type'],
            'initialPresentation' => $presentation,
            'returnContext' => $session->returnContext($this->returnLabel()),
        ]);
    }

    /**
     * List the reusable Content types the actor may start a new item from.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $query     Schema-valid `reusable-content-type` list query.
     *
     * @return  stdClass  Schema-valid `reusable-content-type` list page.
     *
     * @since   2.0.0
     */
    public function listTypes(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $query,
    ): stdClass {
        return $this->catalog->consistently(
            fn (): stdClass => $this->listTypesOperation($context, $snapshot, $query),
        );
    }

    /**
     * Perform `listTypes` inside the one contribution decision its public entry opened.
     *
     * List the reusable Content types the actor may start a new item from.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $query     Schema-valid `reusable-content-type` list query.
     *
     * @return  stdClass  Schema-valid `reusable-content-type` list page.
     *
     * @since   2.0.0
     */
    private function listTypesOperation(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $query,
    ): stdClass {
        $session = $this->session($context, $snapshot, true);
        $this->assertTargetAndContext($session, $query->targetId ?? null, $query->resourceContext ?? null);
        $limit = $query->limit ?? null;
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-limit');
        }
        $offset = 0;
        $cursor = $query->cursor ?? null;
        if ($cursor !== null) {
            if (!is_string($cursor) || preg_match('/^offset:([1-9][0-9]{0,8})$/D', $cursor, $match) !== 1) {
                StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-cursor');
            }
            $offset = (int) $match[1];
        }
        $search = $query->search ?? null;
        $needle = is_string($search) ? mb_strtolower(trim($search)) : '';

        try {
            $definitions = $this->models->contentTypes($context);
        } catch (AuthorizationDenied) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/types-refused');
        }
        $matching = array_values(array_filter(
            $definitions,
            static fn (ContentTypeDefinition $definition): bool => $needle === ''
                || str_contains(mb_strtolower($definition->name), $needle)
                || str_contains(mb_strtolower($definition->handle), $needle),
        ));
        // A Content type whose schema Studio cannot project (an open object, a union, an unsupported
        // field) is not a reusable type for this surface; it is left out rather than failing the page.
        $projectable = [];
        foreach ($matching as $definition) {
            try {
                $projectable[] = [
                    'definition' => $definition,
                    'blueprint' => $this->blueprintReference($context, $definition),
                ];
            } catch (StudioProjectionRejected) {
                continue;
            }
        }
        $page = array_slice($projectable, $offset, $limit);
        $items = [];
        foreach ($page as ['definition' => $definition, 'blueprint' => $blueprint]) {
            $items[] = (object) [
                'reference' => ContentStudioAuthoringDocuments::typeReference($definition),
                'label' => $this->typeLabel($definition),
                'model' => ContentStudioAuthoringDocuments::modelReference($definition),
                'blueprint' => $blueprint,
            ];
        }
        $result = (object) ['items' => $items];
        if ($offset + $limit < count($projectable)) {
            $result->nextCursor = 'offset:' . ($offset + $limit);
        }

        return $this->validated('reusable-content-type', 'listPage', $result);
    }

    /**
     * Open the coordinated authoring session for one exact start source.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $request   Schema-valid `authoring-session` start request.
     *
     * @return  stdClass  Schema-valid `authoring-session` snapshot.
     *
     * @since   2.0.0
     */
    public function start(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $request,
    ): stdClass {
        return $this->catalog->consistently(
            fn (): stdClass => $this->startOperation($context, $snapshot, $request),
        );
    }

    /**
     * Perform `start` inside the one contribution decision its public entry opened.
     *
     * Open the coordinated authoring session for one exact start source.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $request   Schema-valid `authoring-session` start request.
     *
     * @return  stdClass  Schema-valid `authoring-session` snapshot.
     *
     * @since   2.0.0
     */
    private function startOperation(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $request,
    ): stdClass {
        $session = $this->session($context, $snapshot, true);
        $this->assertTargetAndContext($session, $request->targetId ?? null, $request->resourceContext ?? null);
        $source = $request->source ?? null;
        $kind = $source instanceof stdClass ? ($source->kind ?? null) : null;
        $presentation = $request->presentation ?? 'inline';
        if (!is_string($presentation) || !in_array($presentation, self::PRESENTATIONS, true)) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/presentation-unavailable');
        }
        if (!$source instanceof stdClass || !is_string($kind)) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-start');
        }
        $state = match (true) {
            $session->target->intent === StudioAuthoringIntent::Edit && $kind === 'existing'
                => $this->existingState($context, $session),
            $session->target->intent === StudioAuthoringIntent::Create && $kind === 'blank'
                => $this->blankState($context, $session),
            $session->target->intent === StudioAuthoringIntent::Create && $kind === 'from-type'
                => $this->typeState(
                    $context,
                    $session,
                    $this->definitionFromReference($context, $source->type ?? null),
                ),
            default => StudioProducerError::refuse('validation-failed', 'studio.authoring/start-unavailable'),
        };

        $held = $this->held(fn (): array => $this->contexts->rememberStart(
            $context,
            $session->host->resourceId,
            CanonicalJson::stringify($source),
            $presentation,
        ));
        if ($held !== ['source' => CanonicalJson::stringify($source), 'presentation' => $presentation]) {
            StudioProducerError::refuse('conflict', 'studio.authoring/start-already-chosen');
        }
        $this->handed($context, $session, $state);

        return $this->snapshot($session, $state, $source, $presentation, $session->returnContext($this->returnLabel()));
    }

    /**
     * Plan one save outcome against live state and disclose its consequences.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $intent    Schema-valid `authoring-save` intent.
     *
     * @return  stdClass  Schema-valid `authoring-save` plan.
     *
     * @since   2.0.0
     */
    public function planSave(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $intent,
    ): stdClass {
        return $this->catalog->consistently(
            fn (): stdClass => $this->planSaveOperation($context, $snapshot, $intent),
        );
    }

    /**
     * Perform `planSave` inside the one contribution decision its public entry opened.
     *
     * Plan one save outcome against live state and disclose its consequences.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $intent    Schema-valid `authoring-save` intent.
     *
     * @return  stdClass  Schema-valid `authoring-save` plan.
     *
     * @since   2.0.0
     */
    private function planSaveOperation(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $intent,
    ): stdClass {
        $session = $this->session($context, $snapshot, false);
        if (($intent->sessionId ?? null) !== $session->sessionId()) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/session-mismatch');
        }
        $this->recordedStart($context, $session);
        $draft = $intent->draft ?? null;
        $expected = $intent->expected ?? null;
        if (!$draft instanceof stdClass || !$expected instanceof stdClass) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-intent');
        }
        $state = $this->liveState($context, $session, $expected);
        $this->assertExpected($expected, $state);

        return $this->plan($context, $session, $state, $draft);
    }

    /**
     * Commit the item transaction one accepted plan authorizes.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $request   Schema-valid `authoring-save` item request.
     *
     * @return  stdClass  Schema-valid `authoring-save` result.
     *
     * @since   2.0.0
     */
    public function saveItem(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $request,
    ): stdClass {
        return $this->catalog->consistently(
            fn (): stdClass => $this->saveItemOperation($context, $snapshot, $request),
        );
    }

    /**
     * Perform `saveItem` inside the one contribution decision its public entry opened.
     *
     * Commit the item transaction one accepted plan authorizes.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $request   Schema-valid `authoring-save` item request.
     *
     * @return  stdClass  Schema-valid `authoring-save` result.
     *
     * @since   2.0.0
     */
    private function saveItemOperation(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $request,
    ): stdClass {
        $session = $this->session($context, $snapshot, false);
        $draft = $this->draft($request, 'save-item');
        $entry = $draft->entry ?? null;
        if (!$entry instanceof stdClass) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-draft');
        }
        $state = $this->liveState(
            $context,
            $session,
            (object) ['type' => $this->typeReferenceOf($entry->model ?? null)],
        );
        // The accepted plan re-decides and re-admits any item layout against live state before any effect.
        [$plan, $layout] = $this->acceptedPlan($context, $session, $state, $draft, $request);
        $definition = $state->definition;
        if ($definition === null) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/type-required');
        }
        [$title, $slug, $data] = $this->contentValues($entry, $state->model);
        try {
            if ($state->record === null) {
                $record = $this->content->create(
                    $context,
                    $title,
                    $slug,
                    $data,
                    null,
                    $definition->id,
                    ContentStudioProjector::contentEntryId(
                        ContentStudioAuthoringDocuments::draftEntryId($session->key()),
                    ),
                );
            } else {
                $record = $this->content->update(
                    $context,
                    $state->record->entry->id(),
                    $state->record->entry->version(),
                    $title,
                    $slug,
                    $data,
                );
            }
        } catch (VersionConflict) {
            StudioProducerError::refuse('conflict', 'studio.authoring/entry-conflict', $state->entryRevision());
        } catch (InvalidContentData $invalid) {
            StudioProducerError::refuse(
                'validation-failed',
                'studio.authoring/invalid-values',
                details: $invalid->violations,
            );
        } catch (InvalidArgumentException $identity) {
            StudioProducerError::refuse(
                'validation-failed',
                'studio.authoring/invalid-identity',
                details: [$identity->getMessage()],
            );
        } catch (AuthorizationDenied) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/save-refused');
        } catch (ContentModelNotFound) {
            StudioProducerError::refuse('not-found', 'studio.authoring/type-not-found');
        }
        $reason = match (true) {
            $layout === null => null,
            $layout['kind'] === 'kept' => 'kept',
            $layout['kind'] === 'inherit' && $state->itemLayoutActive() => 'inherited',
            default => null,
        };
        // A create pins the type version that is latest when it runs; a layout admitted against the session's
        // version would be detached from its first moment, so the whole save is refused and rolled back.
        if ($reason === 'kept' && $record->contentTypeVersion !== $definition->version) {
            StudioProducerError::refuse('conflict', 'studio.authoring/type-changed');
        }
        if ($reason !== null) {
            $this->keepItemLayout($context, $state, $record, $layout['layout'] ?? null, $reason);
        }
        $successor = $this->editTarget($record, $definition);
        $this->contexts->advance($context, $session->host->resourceId, $successor);
        $advanced = $this->advanced($session, $successor);

        return $this->saveResult(
            $context,
            $advanced,
            $this->existingState($context, $advanced),
            $plan,
            'save-item',
        );
    }

    /**
     * Publish an immutable successor version of the session's reusable type and adopt it.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $request   Schema-valid `authoring-save` new-type-version request.
     *
     * @return  stdClass  Schema-valid `authoring-save` result.
     *
     * @since   2.0.0
     */
    public function saveNewTypeVersion(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $request,
    ): stdClass {
        return $this->catalog->consistently(
            fn (): stdClass => $this->saveNewTypeVersionOperation($context, $snapshot, $request),
        );
    }

    /**
     * Perform `saveNewTypeVersion` inside the one contribution decision its public entry opened.
     *
     * Publish an immutable successor version of the session's reusable type and adopt it.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $request   Schema-valid `authoring-save` new-type-version request.
     *
     * @return  stdClass  Schema-valid `authoring-save` result.
     *
     * @since   2.0.0
     */
    private function saveNewTypeVersionOperation(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $request,
    ): stdClass {
        $session = $this->session($context, $snapshot, false);
        $draft = $this->draft($request, 'save-new-type-version');
        $model = $draft->model ?? null;
        $blueprint = $draft->blueprint ?? null;
        if (!$model instanceof stdClass || !$blueprint instanceof stdClass) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-draft');
        }
        $state = $this->liveState($context, $session, (object) ['type' => $this->typeReferenceOfModel($model)]);
        [$plan] = $this->acceptedPlan($context, $session, $state, $draft, $request);
        $definition = $state->definition;
        if ($definition === null) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/type-required');
        }
        $accepted = $this->acceptedConsequences($request);
        try {
            $successor = $this->models->updateContentType(
                $context,
                $definition->id,
                $definition->version,
                $this->modelName($model, $definition->name),
                $definition->workflowId,
                $this->schemaFromModel($model),
                in_array(self::BREAKING_CHANGE, $accepted, true),
            );
        } catch (VersionConflict) {
            StudioProducerError::refuse('conflict', 'studio.authoring/type-conflict', $state->entryRevision());
        } catch (IncompatibleDefinition) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/breaking-change-unaccepted');
        } catch (InvalidArgumentException) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-model');
        } catch (AuthorizationDenied) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/save-refused');
        } catch (ContentModelNotFound) {
            StudioProducerError::refuse('not-found', 'studio.authoring/type-not-found');
        }
        // The successor keeps the reusable type's Blueprint lineage, never an item layout's identity: while the
        // item keeps its own layout, the coordinated Blueprint is the item's and only the type names the type's.
        $reference = $state->type->blueprint ?? null;
        $predecessor = $reference instanceof stdClass ? ($reference->id ?? null) : null;
        $this->adoptBlueprint(
            $context,
            $successor,
            $blueprint,
            $model,
            $state,
            is_string($predecessor) ? $predecessor : null,
        );
        $advanced = $this->adoptType($context, $session, $state, $successor);

        return $this->saveResult(
            $context,
            $advanced,
            $this->stateFor($context, $advanced, $successor),
            $plan,
            'save-new-type-version',
        );
    }

    /**
     * Create a new reusable type from the session's design, excluding every item value.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $request   Schema-valid `authoring-save` new-type request.
     *
     * @return  stdClass  Schema-valid `authoring-save` result.
     *
     * @since   2.0.0
     */
    public function saveAsNewType(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $request,
    ): stdClass {
        return $this->catalog->consistently(
            fn (): stdClass => $this->saveAsNewTypeOperation($context, $snapshot, $request),
        );
    }

    /**
     * Perform `saveAsNewType` inside the one contribution decision its public entry opened.
     *
     * Create a new reusable type from the session's design, excluding every item value.
     *
     * @param   ExecutionContext           $context   Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session for this dispatch.
     * @param   stdClass                   $request   Schema-valid `authoring-save` new-type request.
     *
     * @return  stdClass  Schema-valid `authoring-save` result.
     *
     * @since   2.0.0
     */
    private function saveAsNewTypeOperation(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $request,
    ): stdClass {
        $session = $this->session($context, $snapshot, false);
        $draft = $this->draft($request, 'save-as-new-type');
        $model = $draft->model ?? null;
        $blueprint = $draft->blueprint ?? null;
        $label = $draft->label ?? null;
        if (!$model instanceof stdClass || !$blueprint instanceof stdClass || !$label instanceof stdClass) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-draft');
        }
        $state = $this->liveState($context, $session, (object) ['type' => $this->typeReferenceOfModel($model)]);
        [$plan] = $this->acceptedPlan($context, $session, $state, $draft, $request);
        $name = $this->modelName($label, $this->modelName($model, 'New content type'));
        $workflow = $state->definition === null ? ContentService::CORE_WORKFLOW_ID : $state->definition->workflowId;
        try {
            $created = $this->models->createContentType(
                $context,
                $this->uniqueHandle($context, $name),
                $name,
                $workflow,
                $this->schemaFromModel($model),
            );
        } catch (InvalidArgumentException) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-model');
        } catch (AuthorizationDenied) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/save-refused');
        } catch (ContentModelNotFound) {
            StudioProducerError::refuse('not-found', 'studio.authoring/workflow-not-found');
        }
        $this->adoptBlueprint($context, $created, $blueprint, $model, $state);
        $advanced = $this->adoptType($context, $session, $state, $created);

        return $this->saveResult(
            $context,
            $advanced,
            $this->stateFor($context, $advanced, $created),
            $plan,
            'save-as-new-type',
        );
    }

    /**
     * Re-derive the trusted session behind one authorized dispatch.
     *
     * @param   ExecutionContext           $context       Authenticated administrator request.
     * @param   StudioHostSessionSnapshot  $snapshot      Authorized host session.
     * @param   bool                       $advanceStale  Whether a moved edit target may be adopted (reads)
     *          rather than refused as a conflict (writes).
     *
     * @return  ContentStudioAuthoringSession  Trusted session coordinates.
     *
     * @since   2.0.0
     */
    private function session(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        bool $advanceStale,
    ): ContentStudioAuthoringSession {
        $host = $snapshot->session;
        if ($host->resourceKind !== StudioResourceKind::ContentAuthoring) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/session-kind');
        }
        try {
            $target = $this->held(fn (): ContentStudioAuthoringTarget => $this->contexts->resolve(
                $context,
                $host->resourceId,
            ));
        } catch (ContentStudioAuthoringContextStale $stale) {
            if (!$advanceStale) {
                StudioProducerError::refuse(
                    'conflict',
                    'studio.authoring/context-stale',
                    $stale->current->entryRevision,
                );
            }
            $this->held(fn () => $this->contexts->advance($context, $host->resourceId, $stale->current));
            $target = $stale->current;
        }

        return new ContentStudioAuthoringSession($host, $target, $snapshot->generation, $snapshot->permissions);
    }

    /**
     * Run one context-authority call, answering a refused binding with the host's forbidden refusal.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Call that may find the opaque context absent, foreign or expired.
     *
     * @return  T  The call's own result.
     *
     * @since   2.0.0
     */
    private function held(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ContentStudioAuthoringContextRefused) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/context-refused');
        }
    }

    /**
     * The start source the session recorded when it opened, required before any save is planned.
     *
     * @param   ExecutionContext               $context  Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session  Trusted session.
     *
     * @return  stdClass  Canonical start source.
     *
     * @since   2.0.0
     */
    private function recordedStart(ExecutionContext $context, ContentStudioAuthoringSession $session): stdClass
    {
        $recorded = $this->held(fn (): ?array => $this->contexts->startOf($context, $session->host->resourceId));
        $start = $recorded === null ? null : json_decode($recorded['source'], false, 16, JSON_THROW_ON_ERROR);
        if (!$start instanceof stdClass) {
            StudioProducerError::refuse('conflict', 'studio.authoring/start-required');
        }

        return $start;
    }

    /**
     * Require the request to address this session's target and exact resource context.
     *
     * @param   ContentStudioAuthoringSession  $session          Trusted session.
     * @param   mixed                          $targetId         Request target identifier.
     * @param   mixed                          $resourceContext  Request resource context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertTargetAndContext(
        ContentStudioAuthoringSession $session,
        mixed $targetId,
        mixed $resourceContext,
    ): void {
        if ($targetId !== ContentStudioAuthoringTarget::TARGET_ID) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/unknown-target');
        }
        if (
            !$resourceContext instanceof stdClass
            || CanonicalJson::stringify($resourceContext) !== CanonicalJson::stringify($session->resourceContext())
        ) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/resource-context-mismatch');
        }
    }

    /**
     * Project the live state of one persisted item the session edits.
     *
     * @param   ExecutionContext               $context  Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session  Trusted edit session.
     *
     * @return  ContentStudioAuthoringState  Exact coordinated projection.
     *
     * @since   2.0.0
     */
    private function existingState(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
    ): ContentStudioAuthoringState {
        $target = $session->target;
        $entryId = $target->entryId === null ? null : ContentStudioProjector::contentEntryId($target->entryId);
        $typeId = $target->modelId === null ? null : ContentStudioProjector::contentTypeId($target->modelId);
        $version = $target->modelVersion === null
            ? null
            : ContentStudioProjector::contentTypeVersion($target->modelVersion);
        if ($entryId === null || $typeId === null || $version === null) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/context-refused');
        }
        try {
            $record = $this->content->get($context, $entryId);
            $definition = $this->models->contentType($context, $typeId, $version);
            $workflow = $record->workflowId === ContentService::CORE_WORKFLOW_ID
                ? null
                : $this->models->workflow($context, $record->workflowId, $record->workflowVersion);
        } catch (ContentNotFound | ContentModelNotFound) {
            StudioProducerError::refuse('not-found', 'studio.authoring/item-not-found');
        } catch (AuthorizationDenied) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/item-refused');
        }

        return $this->stateOf($context, $session, $definition, $record, $workflow);
    }

    /**
     * Project the live state of one reusable type with a provisional item.
     *
     * @param   ExecutionContext               $context     Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session     Trusted create session.
     * @param   ContentTypeDefinition          $definition  Exact authorized type version.
     *
     * @return  ContentStudioAuthoringState  Exact coordinated projection.
     *
     * @since   2.0.0
     */
    private function typeState(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentTypeDefinition $definition,
    ): ContentStudioAuthoringState {
        return $this->stateOf($context, $session, $definition, null, null);
    }

    /**
     * Project one type-bound state, provisioning the type's composition when it has none yet.
     *
     * A stored composition that is still a draft with no roots is handed to the session as the default
     * composition derived from the projected model (App ADR 0024); its coordinates are the stored ones and
     * nothing is written until a type save. A persisted item that keeps its own layout is handed that layout
     * exactly as stored, never a derived default, and the coordinated Blueprint is the item's while the
     * reusable type still names the type's (App ADR 0025). A layout made for another type version, or locked to
     * a theme that is no longer published, is kept but not used: the type's layout is handed with the
     * `kumwe.app/item-layout-detached` diagnostic. Under the `denied` item-composition policy stored layouts are
     * ignored without a diagnostic.
     *
     * @param   ExecutionContext               $context     Authenticated request.
     * @param   ContentStudioAuthoringSession  $session     Trusted session.
     * @param   ContentTypeDefinition          $definition  Exact type version.
     * @param   ?ContentRecord                 $record      Persisted item, or null.
     * @param   ?WorkflowDefinition            $workflow    Custom workflow, or null.
     *
     * @return  ContentStudioAuthoringState  Exact coordinated projection.
     *
     * @since   2.0.0
     */
    private function stateOf(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentTypeDefinition $definition,
        ?ContentRecord $record,
        ?WorkflowDefinition $workflow,
    ): ContentStudioAuthoringState {
        $overrides = null;
        $itemLayout = null;
        try {
            $composition = $this->compositions->find($context, $definition->id, $definition->version)
                ?? $this->compositions->provision($context, $definition->id, $definition->version, self::RENDERERS);
            $model = $this->projector->contentModel($context, $definition, $composition->binding);
            $overrides = $record === null ? null : $this->bindings->overrides($context->site(), $record->entry->id());
            $entry = $record === null
                ? $this->draftEntry($session, ContentStudioAuthoringDocuments::modelReference($definition))
                : $this->projector->entry(
                    $context,
                    $record,
                    $definition,
                    $workflow,
                    $overrides,
                    $composition->binding,
                );
            if ($overrides !== null && $this->itemComposition->allowsItemLayouts()) {
                $itemLayout = $this->compositions->itemLayout(
                    $context,
                    $definition->id,
                    $definition->version,
                    $overrides,
                );
            }
        } catch (StudioProjectionRejected) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/projection-rejected');
        } catch (AuthorizationDenied) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/item-refused');
        }
        $typeReference = self::compositionReference($composition);
        $typeBlueprint = StudioContentDefaultComposition::presented(
            $composition->blueprint->document(),
            $model,
            $this->catalog->renderableBlockLocks(),
        );
        $blueprint = $typeBlueprint;
        $blueprintReference = $typeReference;
        $diagnostics = [];
        if ($itemLayout !== null) {
            $blueprint = $itemLayout->document();
            $blueprintReference = (object) [
                'id' => $itemLayout->id,
                'version' => $itemLayout->version,
                'revision' => $itemLayout->revision,
            ];
        } elseif (
            $overrides?->itemBlueprintRevision !== null
            && $this->itemComposition->allowsItemLayouts()
        ) {
            $diagnostics[] = ContentStudioAuthoringDocuments::diagnostic(
                self::ITEM_LAYOUT_DETACHED,
                'warning',
                $this->translator->translate('core.administrator.content_form.studio_item_layout_detached'),
            );
        }
        $coordinates = (object) [
            'type' => ContentStudioAuthoringDocuments::typeReference($definition),
            'model' => ContentStudioAuthoringDocuments::modelReference($definition),
            'blueprint' => $blueprintReference,
            'entry' => (object) ['id' => $entry->id, 'revision' => $entry->revision],
        ];

        return new ContentStudioAuthoringState(
            $coordinates,
            ContentStudioAuthoringDocuments::typeDefinition(
                $definition,
                $typeReference,
                $this->itemComposition->policy,
            ),
            $model,
            $blueprint,
            $entry,
            $definition,
            $record,
            $typeBlueprint,
            $overrides,
            $diagnostics,
        );
    }

    /**
     * Project the provisional state of a blank canvas.
     *
     * @param   ExecutionContext               $context  Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session  Trusted create session.
     *
     * @return  ContentStudioAuthoringState  Deterministic provisional projection.
     *
     * @since   2.0.0
     */
    private function blankState(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
    ): ContentStudioAuthoringState {
        $key = $session->key();
        $modelReference = (object) [
            'id' => ContentStudioAuthoringDocuments::draftModelId($key),
            'version' => self::DRAFT_MODEL_VERSION,
            'revision' => self::DRAFT_MODEL_REVISION,
        ];
        $model = $this->validated('content-model', null, (object) [
            'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
            'kind' => 'content-model',
            'id' => $modelReference->id,
            'version' => $modelReference->version,
            'revision' => $modelReference->revision,
            'owner' => (object) ContentStudioAuthoringDocuments::OWNER,
            'status' => 'draft',
            'label' => ContentStudioAuthoringDocuments::message('kumwe.app/new-content-type', 'New content type'),
            'fields' => [
                self::entryPropertyField('title', 'Title', 'studio.semantic/title', 0, 255),
                self::entryPropertyField('slug', 'Slug', 'kumwe.content/slug', 1, 160),
            ],
            'relationships' => [],
        ]);
        $blueprintReference = (object) [
            'id' => ContentStudioAuthoringDocuments::draftBlueprintId($key),
            'version' => '1.0.0',
            'revision' => self::DRAFT_BLUEPRINT_REVISION,
        ];
        $blueprint = $this->validated('blueprint', null, (object) [
            'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
            'kind' => 'blueprint',
            'id' => $blueprintReference->id,
            'version' => $blueprintReference->version,
            'revision' => $blueprintReference->revision,
            'owner' => (object) ContentStudioAuthoringDocuments::OWNER,
            'status' => 'draft',
            'label' => ContentStudioAuthoringDocuments::message('kumwe.app/content-blueprint', 'Content composition'),
            'model' => $modelReference,
            'dependencyLock' => (object) [
                'theme' => $this->theme->reference($context->site())->document(),
                'blocks' => $this->blockLocks(),
            ],
            'roots' => [],
        ]);
        $entry = $this->draftEntry($session, $modelReference);

        return new ContentStudioAuthoringState(
            (object) [
                'model' => $modelReference,
                'blueprint' => $blueprintReference,
                'entry' => (object) ['id' => $entry->id, 'revision' => $entry->revision],
            ],
            null,
            $model,
            $blueprint,
            $entry,
            null,
            null,
        );
    }

    /**
     * Project live state for the coordinates a save intent or request claims.
     *
     * @param   ExecutionContext               $context   Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session   Trusted session.
     * @param   stdClass                       $expected  Claimed coordinates; only `type` selects the projection.
     *
     * @return  ContentStudioAuthoringState  Exact live projection.
     *
     * @since   2.0.0
     */
    private function liveState(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        stdClass $expected,
    ): ContentStudioAuthoringState {
        if ($session->target->intent === StudioAuthoringIntent::Edit) {
            return $this->existingState($context, $session);
        }
        $type = $expected->type ?? null;
        if ($type === null) {
            return $this->blankState($context, $session);
        }

        return $this->typeState($context, $session, $this->definitionFromReference($context, $type));
    }

    /**
     * Refuse a save whose claimed coordinates differ from live state.
     *
     * @param   stdClass                     $expected  Claimed coordinates.
     * @param   ContentStudioAuthoringState  $state     Live projection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertExpected(stdClass $expected, ContentStudioAuthoringState $state): void
    {
        if (CanonicalJson::stringify($expected) !== CanonicalJson::stringify($state->coordinates)) {
            StudioProducerError::refuse('conflict', 'studio.authoring/expected-mismatch', $state->entryRevision());
        }
    }

    /**
     * Build the host-reviewed plan for one draft against live state.
     *
     * @param   ExecutionContext               $context  Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session  Trusted session.
     * @param   ContentStudioAuthoringState    $state    Live projection.
     * @param   stdClass                       $draft    Save draft.
     *
     * @return  stdClass  Schema-valid `authoring-save` plan.
     *
     * @since   2.0.0
     */
    private function plan(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringState $state,
        stdClass $draft,
    ): stdClass {
        return $this->planned($context, $session, $state, $draft)[0];
    }

    /**
     * Build the host-reviewed plan for one draft against live state, with its item layout decision.
     *
     * A save-item draft carrying an item layout is refused unless the item-composition policy allows item
     * layouts and the session may edit Blueprints; every save-item is fenced against the reusable type the
     * session was last handed; and the layout is decided and admitted here, so the same checks run when the
     * plan is offered and again, against live state, before a save has any effect (App ADR 0025). A save that
     * changes the layout a published item's public page shows, whether it keeps a new layout or goes back to
     * the type's, discloses it and is confirmed.
     *
     * @param   ExecutionContext               $context  Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session  Trusted session.
     * @param   ContentStudioAuthoringState    $state    Live projection.
     * @param   stdClass                       $draft    Save draft.
     *
     * @return  array{0: stdClass, 1: ?array{kind: 'inherit'|'unchanged'|'kept', layout: ?StoredStudioArtifact}}
     *          Schema-valid `authoring-save` plan, and the item layout decision of a save-item draft that
     *          carries a layout, or null.
     *
     * @since   2.0.0
     */
    private function planned(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringState $state,
        stdClass $draft,
    ): array {
        $layout = null;
        $outcome = $draft->outcome ?? null;
        if (!is_string($outcome) || !in_array($outcome, $this->admittedOutcomes($state), true)) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/outcome-unavailable');
        }
        $consequences = [];
        $confirmation = false;
        switch ($outcome) {
            case 'save-item':
                $itemLayout = property_exists($draft, 'itemBlueprint');
                if ($itemLayout && !$this->itemComposition->allowsItemLayouts()) {
                    StudioProducerError::refuse('validation-failed', 'studio.authoring/item-composition-denied');
                }
                if ($itemLayout && !in_array(self::EDIT_BLUEPRINT, $session->permissions, true)) {
                    StudioProducerError::refuse('forbidden', 'studio.authoring/item-layout-refused');
                }
                $this->assertHandedType($context, $session, $state);
                $affected = ['entry'];
                $consequences[] = ContentStudioAuthoringDocuments::diagnostic(
                    $state->record === null ? 'kumwe.app/item-created' : 'kumwe.app/item-revision-advances',
                    'information',
                    $state->record === null
                        ? $this->translator->translate('core.administrator.content_form.studio_item_created')
                        : $this->translator->translate('core.administrator.content_form.studio_item_revision_advances'),
                );
                if (!$itemLayout) {
                    break;
                }
                $affected = ['entry', 'blueprint'];
                $layout = $this->itemLayoutDecision($context, $session, $state, $draft->itemBlueprint);
                $live = ($state->entry->status ?? null) === 'published';
                if ($layout['kind'] === 'kept') {
                    $consequences[] = ContentStudioAuthoringDocuments::diagnostic(
                        self::ITEM_LAYOUT_KEPT,
                        'information',
                        $this->translator->translate('core.administrator.content_form.studio_item_layout_kept'),
                    );
                    if ($live) {
                        $consequences[] = $this->liveLayoutConsequence();
                    }
                    // The first divergence from the type's layout, and every layout change a public page shows
                    // at once, are confirmed.
                    $confirmation = $live || !$state->itemLayoutActive();
                } elseif ($layout['kind'] === 'inherit' && $state->itemLayoutActive()) {
                    $consequences[] = ContentStudioAuthoringDocuments::diagnostic(
                        self::ITEM_LAYOUT_INHERITED,
                        'information',
                        $this->translator->translate('core.administrator.content_form.studio_item_layout_inherited'),
                    );
                    // Going back to the type's layout changes a published page at once too, so it is disclosed
                    // and confirmed exactly as a kept layout is.
                    if ($live) {
                        $consequences[] = $this->liveLayoutConsequence();
                    }
                    $confirmation = $live;
                }
                break;
            case 'save-new-type-version':
                $definition = $state->definition;
                $model = $draft->model ?? null;
                if ($definition === null || !$model instanceof stdClass) {
                    StudioProducerError::refuse('validation-failed', 'studio.authoring/type-required');
                }
                $affected = ['model', 'blueprint', 'reusable-content-type'];
                $confirmation = true;
                $consequences[] = ContentStudioAuthoringDocuments::diagnostic(
                    self::DEPENDENT_ENTRIES,
                    'warning',
                    $this->translator->translate('core.administrator.content_form.studio_dependent_entries_remain'),
                );
                $consequences[] = ContentStudioAuthoringDocuments::diagnostic(
                    self::ITEM_ADOPTS,
                    'information',
                    $this->translator->translate('core.administrator.content_form.studio_item_adopts_successor'),
                );
                if ($this->breakingChanges($context, $definition, $model) !== []) {
                    $consequences[] = ContentStudioAuthoringDocuments::diagnostic(
                        self::BREAKING_CHANGE,
                        'warning',
                        $this->translator->translate('core.administrator.content_form.studio_breaking_schema_change'),
                    );
                }
                if ($state->itemLayoutActive()) {
                    $consequences[] = $this->promotedConsequence();
                }
                break;
            default:
                $affected = ['model', 'blueprint', 'reusable-content-type'];
                $confirmation = true;
                $consequences[] = ContentStudioAuthoringDocuments::diagnostic(
                    'kumwe.app/new-content-type',
                    'information',
                    $state->record === null
                        ? $this->translator->translate('core.administrator.content_form.studio_new_type_for_new_item')
                        : $this->translator->translate('core.administrator.content_form.studio_new_type_for_item'),
                );
                if ($state->itemLayoutActive()) {
                    $consequences[] = $this->promotedConsequence();
                }
                break;
        }
        $id = self::planId($session, $state, $outcome, $draft);

        return [$this->validated('authoring-save', 'savePlan', (object) [
            'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
            'kind' => 'authoring-save-plan',
            'id' => $id,
            'revision' => 'plan-r1',
            'successorContext' => ContentStudioAuthoringDocuments::returnContext(
                $session->key(),
                $id,
                $this->returnLabel(),
            ),
            'sessionId' => $session->sessionId(),
            'outcome' => $outcome,
            'expected' => $state->coordinates,
            'affectedArtifacts' => $affected,
            'consequences' => $consequences,
            'confirmationRequired' => $confirmation,
        ]), $layout];
    }

    /**
     * The consequence a save item discloses when it changes the layout a published item's public page shows.
     *
     * @return  stdClass  Schema-valid common `diagnostic`.
     *
     * @since   2.0.0
     */
    private function liveLayoutConsequence(): stdClass
    {
        return ContentStudioAuthoringDocuments::diagnostic(
            self::ITEM_LAYOUT_LIVE,
            'warning',
            $this->translator->translate('core.administrator.content_form.studio_item_layout_live'),
        );
    }

    /**
     * The consequence a type save from an item that keeps its own layout discloses.
     *
     * @return  stdClass  Schema-valid common `diagnostic`.
     *
     * @since   2.0.0
     */
    private function promotedConsequence(): stdClass
    {
        return ContentStudioAuthoringDocuments::diagnostic(
            self::ITEM_LAYOUT_PROMOTED,
            'information',
            $this->translator->translate('core.administrator.content_form.studio_item_layout_promoted'),
        );
    }

    /**
     * Recompute the plan a save request references and require exact agreement.
     *
     * @param   ExecutionContext               $context  Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session  Trusted session.
     * @param   ContentStudioAuthoringState    $state    Live projection.
     * @param   stdClass                       $draft    Save draft.
     * @param   stdClass                       $request  Save request carrying the plan reference.
     *
     * @return  array{0: stdClass, 1: ?array{kind: 'inherit'|'unchanged'|'kept', layout: ?StoredStudioArtifact}}
     *          The recomputed plan, and the item layout decision it re-made against live state, or null.
     *
     * @since   2.0.0
     */
    private function acceptedPlan(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringState $state,
        stdClass $draft,
        stdClass $request,
    ): array {
        $this->recordedStart($context, $session);
        [$plan, $layout] = $this->planned($context, $session, $state, $draft);
        $reference = $request->plan ?? null;
        if (
            !$reference instanceof stdClass
            || ($reference->id ?? null) !== $plan->id
            || ($reference->revision ?? null) !== $plan->revision
            || CanonicalJson::stringify($reference->successorContext ?? null)
                !== CanonicalJson::stringify($plan->successorContext)
        ) {
            StudioProducerError::refuse('conflict', 'studio.authoring/plan-mismatch', $state->entryRevision());
        }
        $accepted = $this->acceptedConsequences($request);
        $codes = [];
        foreach (is_array($plan->consequences) ? $plan->consequences : [] as $consequence) {
            if ($consequence instanceof stdClass && is_string($consequence->code ?? null)) {
                $codes[] = $consequence->code;
            }
        }
        foreach ($accepted as $code) {
            if (!in_array($code, $codes, true)) {
                StudioProducerError::refuse('validation-failed', 'studio.authoring/unknown-consequence');
            }
        }
        if ($plan->confirmationRequired && array_diff($codes, $accepted) !== []) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/consequences-unaccepted');
        }

        return [$plan, $layout];
    }

    /**
     * Read the accepted consequence codes of one save request.
     *
     * @param   stdClass  $request  Save request.
     *
     * @return  list<string>  Accepted qualified consequence codes.
     *
     * @since   2.0.0
     */
    private function acceptedConsequences(stdClass $request): array
    {
        $accepted = $request->acceptedConsequences ?? [];
        $codes = [];
        foreach (is_array($accepted) ? $accepted : [] as $code) {
            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Assemble the result of one accepted save around the session's successor state.
     *
     * @param   ExecutionContext               $context  Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session  Session advanced to its successor target.
     * @param   ContentStudioAuthoringState    $state    Fresh projection after the effect.
     * @param   stdClass                       $plan     Accepted plan.
     * @param   string                         $outcome  Accepted outcome.
     *
     * @return  stdClass  Schema-valid `authoring-save` result.
     *
     * @since   2.0.0
     */
    private function saveResult(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringState $state,
        stdClass $plan,
        string $outcome,
    ): stdClass {
        // Studio reconciles every save against the start the session opened with, so the recorded start
        // is reported unchanged even after the session created its item or adopted a new reusable type.
        $start = $this->recordedStart($context, $session);
        $successorContext = $plan->successorContext;
        if (!$successorContext instanceof stdClass) {
            StudioProducerError::refuse('internal', 'studio.authoring/plan-corrupt');
        }
        // The initial presentation is an accepted base; Studio keeps any newer local presentation.
        $recorded = $this->held(fn (): ?array => $this->contexts->startOf($context, $session->host->resourceId));
        $presentation = $recorded['presentation'] ?? null;
        if (!is_string($presentation) || !in_array($presentation, self::PRESENTATIONS, true)) {
            StudioProducerError::refuse('conflict', 'studio.authoring/start-required');
        }
        $this->handed($context, $session, $state);
        $snapshot = $this->snapshot($session, $state, $start, $presentation, $successorContext);

        return $this->validated('authoring-save', 'saveResult', (object) [
            'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
            'kind' => 'authoring-save-result',
            'outcome' => $outcome,
            'plan' => (object) [
                'id' => $plan->id,
                'revision' => $plan->revision,
                'successorContext' => $plan->successorContext,
            ],
            'session' => $snapshot,
        ]);
    }

    /**
     * Assemble one session snapshot document.
     *
     * @param   ContentStudioAuthoringSession  $session        Trusted session.
     * @param   ContentStudioAuthoringState    $state          Coordinated projection.
     * @param   stdClass                       $start          Start source Studio requested.
     * @param   string                         $presentation   Current presentation state.
     * @param   stdClass                       $returnContext  Return pointer the snapshot carries.
     *
     * @return  stdClass  Schema-valid `authoring-session` snapshot.
     *
     * @since   2.0.0
     */
    private function snapshot(
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringState $state,
        stdClass $start,
        string $presentation,
        stdClass $returnContext,
    ): stdClass {
        $document = (object) [
            'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
            'kind' => 'authoring-session',
            'sessionId' => $session->sessionId(),
            'sessionGeneration' => $session->generation,
            'target' => $this->catalog->declaration(),
            'resourceContext' => $session->resourceContext(),
            'start' => $start,
        ];
        if ($state->type !== null) {
            $document->type = $state->type;
        }
        $document->state = (object) [
            'coordinates' => $state->coordinates,
            'model' => $state->model,
            'blueprint' => $state->blueprint,
            'entry' => $state->entry,
            'dirty' => [],
            'diagnostics' => $state->diagnostics,
        ];
        $document->capabilities = (object) [
            'modes' => ['model', 'blueprint', 'content'],
            'presentationStates' => self::PRESENTATIONS,
            'saveOutcomes' => self::saveOutcomes(),
        ];
        $document->presentation = (object) ['current' => $presentation, 'returnContext' => $returnContext];
        $document->contributionGeneration = $this->contributionGeneration();
        $document->extensions = (object) [
            'kumwe.app/return' => (object) ['path' => $session->target->returnPath],
        ];

        return $this->validated('authoring-session', 'snapshot', $document);
    }

    /**
     * The save outcomes every contextual Content session declares.
     *
     * The declaration is constant for the session: Studio reconciles each save result against the
     * capabilities the session started with, and a blank canvas that becomes a reusable type must then be
     * able to save its item in the same session. Which outcome the current state actually admits is the
     * plan's decision, and a plan for an outcome the state cannot take is refused by the host.
     *
     * @return  list<string>  Declared outcomes.
     *
     * @since   2.0.0
     */
    private static function saveOutcomes(): array
    {
        return ['save-item', 'save-new-type-version', 'save-as-new-type'];
    }

    /**
     * The save outcomes one state admits right now.
     *
     * @param   ContentStudioAuthoringState  $state  Coordinated projection.
     *
     * @return  list<string>  Admitted outcomes; a blank canvas admits only a new reusable type.
     *
     * @since   2.0.0
     */
    private function admittedOutcomes(ContentStudioAuthoringState $state): array
    {
        return $state->type === null
            ? ['save-as-new-type']
            : ['save-item', 'save-new-type-version', 'save-as-new-type'];
    }

    /**
     * Advance the session's context to a successor type and, for a persisted item, adopt it.
     *
     * An item that keeps its own layout saved that layout as the successor's, so its pointer is cleared in the
     * same transaction and the item follows the successor (App ADR 0025).
     *
     * @param   ExecutionContext               $context    Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session    Trusted session.
     * @param   ContentStudioAuthoringState    $state      Projection before the effect.
     * @param   ContentTypeDefinition          $successor  Newly published type version or type.
     *
     * @return  ContentStudioAuthoringSession  Session bound to the successor target.
     *
     * @since   2.0.0
     */
    private function adoptType(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringState $state,
        ContentTypeDefinition $successor,
    ): ContentStudioAuthoringSession {
        if ($state->record === null) {
            $target = new ContentStudioAuthoringTarget(
                StudioAuthoringIntent::Create,
                ContentStudioProjector::modelId($successor->id),
                ContentStudioProjector::modelVersion($successor->version),
                ContentStudioProjector::modelRevision($successor->version),
                null,
                null,
                '/administrator/content/new?content_type=' . rawurlencode($successor->id),
            );
        } else {
            try {
                $record = $this->content->adoptContentType(
                    $context,
                    $state->record->entry->id(),
                    $state->record->entry->version(),
                    $successor->id,
                    $successor->version,
                );
            } catch (VersionConflict) {
                StudioProducerError::refuse('conflict', 'studio.authoring/entry-conflict', $state->entryRevision());
            } catch (InvalidContentData $invalid) {
                StudioProducerError::refuse(
                    'validation-failed',
                    'studio.authoring/invalid-values',
                    details: $invalid->violations,
                );
            } catch (InvalidArgumentException) {
                StudioProducerError::refuse('validation-failed', 'studio.authoring/workflow-mismatch');
            } catch (AuthorizationDenied) {
                StudioProducerError::refuse('forbidden', 'studio.authoring/save-refused');
            } catch (ContentNotFound | ContentModelNotFound) {
                StudioProducerError::refuse('not-found', 'studio.authoring/item-not-found');
            }
            // The type just saved composes the item's own layout, so the item now follows that type instead.
            if ($state->itemLayoutActive()) {
                $this->keepItemLayout($context, $state, $record, null, 'promoted');
            }
            $target = $this->editTarget($record, $successor);
        }
        $this->contexts->advance($context, $session->host->resourceId, $target);

        return $this->advanced($session, $target);
    }

    /**
     * Project the state of an advanced session for one exact type.
     *
     * @param   ExecutionContext               $context     Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session     Advanced session.
     * @param   ContentTypeDefinition          $definition  Exact type version the session now binds.
     *
     * @return  ContentStudioAuthoringState  Fresh projection.
     *
     * @since   2.0.0
     */
    private function stateFor(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentTypeDefinition $definition,
    ): ContentStudioAuthoringState {
        return $session->target->intent === StudioAuthoringIntent::Edit
            ? $this->existingState($context, $session)
            : $this->typeState($context, $session, $definition);
    }

    /**
     * The same session rebound to a successor target.
     *
     * @param   ContentStudioAuthoringSession  $session  Trusted session.
     * @param   ContentStudioAuthoringTarget   $target   Successor target.
     *
     * @return  ContentStudioAuthoringSession  Rebound session.
     *
     * @since   2.0.0
     */
    private function advanced(
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringTarget $target,
    ): ContentStudioAuthoringSession {
        return new ContentStudioAuthoringSession($session->host, $target, $session->generation, $session->permissions);
    }

    /**
     * The exact edit target of one persisted record and its pinned type.
     *
     * @param   ContentRecord          $record      Persisted item.
     * @param   ContentTypeDefinition  $definition  Type version the record pins.
     *
     * @return  ContentStudioAuthoringTarget  Edit target.
     *
     * @since   2.0.0
     */
    private function editTarget(ContentRecord $record, ContentTypeDefinition $definition): ContentStudioAuthoringTarget
    {
        return new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Edit,
            ContentStudioProjector::modelId($definition->id),
            ContentStudioProjector::modelVersion($record->contentTypeVersion),
            ContentStudioProjector::modelRevision($record->contentTypeVersion),
            ContentStudioProjector::entryId($record->entry->id()),
            ContentStudioProjector::entryRevision($record->entry->version()),
            '/administrator/content/' . rawurlencode($record->entry->id()) . '/edit',
        );
    }

    /**
     * Persist an authored Blueprint as the composition of one new type version.
     *
     * @param   ExecutionContext             $context      Authenticated administrator request.
     * @param   ContentTypeDefinition        $definition   Newly published type version.
     * @param   stdClass                     $blueprint    Authored Blueprint document.
     * @param   stdClass                     $model        Authored model whose field identities are persisted
     *          with the binding.
     * @param   ContentStudioAuthoringState  $handed       Live state the save was planned against; an untouched
     *          derived default of the type's layout it handed is stored as an empty draft, and an item's own
     *          layout it handed is stored as the type's layout without the item's marks.
     * @param   ?string                      $predecessor  Blueprint identity of the version this one succeeds, or null
     *          for a new reusable type.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function adoptBlueprint(
        ExecutionContext $context,
        ContentTypeDefinition $definition,
        stdClass $blueprint,
        stdClass $model,
        ContentStudioAuthoringState $handed,
        ?string $predecessor = null,
    ): void {
        // A published Blueprint must compose at least one root; an empty layout stays the type's draft
        // composition so public rendering keeps the structured template until an author composes a layout. An
        // authored draft layout publishes as before.
        $roots = $blueprint->roots ?? null;
        $status = is_array($roots) && $roots !== [] ? 'published' : 'draft';
        // The session locks every block it can author; the stored reusable Blueprint locks exactly the
        // blocks it composes at the session's coordinates, and each of those must have a live renderer, or
        // the save is refused here rather than the public page later.
        $stored = json_decode(json_encode($blueprint, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        if (!$stored instanceof stdClass || !$stored->dependencyLock instanceof stdClass) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-draft');
        }
        // The derived default layout saved without change (App ADR 0024) is stored as an empty draft, not as
        // its roots: the default is then derived again from the current model on every load, so a later
        // model-only save never publishes a layout no author touched, and a removed field is never persisted
        // as a binding.
        // An item that keeps its own layout was handed that layout, so the type's own handed layout decides.
        $handedDefault = $handed->typeBlueprint ?? $handed->blueprint;
        if (is_array($roots) && StudioContentDefaultComposition::untouched($handedDefault, $handed->model, $roots)) {
            $roots = [];
            $stored->roots = [];
            $status = 'draft';
        }
        // A type saved from an item's own layout (App ADR 0025) never carries the item's pin or label.
        $extensions = $stored->extensions ?? null;
        $itemExtension = StudioContentCompositionService::ITEM_EXTENSION;
        if ($extensions instanceof stdClass && property_exists($extensions, $itemExtension)) {
            unset($extensions->{$itemExtension});
            if (get_object_vars($extensions) === []) {
                unset($stored->extensions);
            }
        }
        $label = $stored->label ?? null;
        if ($label instanceof stdClass && ($label->key ?? null) === 'kumwe.app/content-item-blueprint') {
            $stored->label = ContentStudioAuthoringDocuments::message(
                'kumwe.app/content-blueprint',
                'Content composition',
            );
        }
        // The browser's draft may carry the narrower lock of the Blueprint it started from, so the stored
        // lock is rebuilt from the session catalog the author composed against.
        $stored->dependencyLock->blocks = $this->lockedBlocks(is_array($roots) ? $roots : []);
        try {
            $this->compositions->adopt(
                $context,
                $definition->id,
                $definition->version,
                $stored,
                $this->catalog->renderableBlockLocks(),
                $status,
                $predecessor,
                array_map(self::fieldIdentity(...), $this->dataFields($model)),
            );
        } catch (StudioCompositionLockMismatch) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/unlocked-block');
        }
    }

    /**
     * The session catalogue's locks of exactly the block types one node tree composes.
     *
     * A composed block the catalogue no longer offers (its extension was disabled or removed) cannot be
     * locked, so the save is refused rather than storing a layout whose lock does not cover it.
     *
     * @param   array<mixed>  $roots  Authored root nodes.
     *
     * @return  list<stdClass>  One catalogue lock per composed block type, in catalogue order.
     *
     * @since   2.0.0
     */
    private function lockedBlocks(array $roots): array
    {
        $used = self::usedBlockTypes($roots);
        $locks = array_values(array_filter(
            $this->catalog->blockLocks(),
            static fn (stdClass $lock): bool => is_string($lock->type ?? null) && isset($used[$lock->type]),
        ));
        if (count($locks) !== count($used)) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/unlocked-block');
        }

        return $locks;
    }

    /**
     * Decide what Save item does with the item layout a draft carries, validating it before any effect.
     *
     * The layout must be made from the Blueprint the session was handed and lock the item's model. Roots equal
     * to the type's handed layout inherit it, so nothing is stored and an active item layout is cleared; empty
     * roots under a non-empty type layout are refused; any other layout is minted and admitted as the item's
     * own, and is unchanged when it is the revision the item already uses.
     *
     * @param   ExecutionContext               $context        Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session        Trusted item session.
     * @param   ContentStudioAuthoringState    $state          Live projection.
     * @param   mixed                          $itemBlueprint  The draft's `itemBlueprint` document.
     *
     * @return  array{kind: 'inherit'|'unchanged'|'kept', layout: ?StoredStudioArtifact}  The decision, with the
     *          admitted layout unless the item inherits its type's layout.
     *
     * @since   2.0.0
     */
    private function itemLayoutDecision(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringState $state,
        mixed $itemBlueprint,
    ): array {
        $definition = $state->definition;
        $base = $state->type->blueprint ?? null;
        $roots = $itemBlueprint instanceof stdClass ? ($itemBlueprint->roots ?? null) : null;
        if (
            !$itemBlueprint instanceof stdClass
            || $definition === null
            || !$base instanceof stdClass
            || !is_array($roots)
            || !array_is_list($roots)
        ) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-draft');
        }
        try {
            $sameBase = CanonicalJson::stringify((object) [
                'id' => $itemBlueprint->id ?? null,
                'version' => $itemBlueprint->version ?? null,
                'revision' => $itemBlueprint->revision ?? null,
            ]) === CanonicalJson::stringify($state->coordinates->blueprint ?? null);
            $sameModel = CanonicalJson::stringify($itemBlueprint->model ?? null)
                === CanonicalJson::stringify($state->coordinates->model ?? null);
            $typeRoots = ($state->typeBlueprint ?? $state->blueprint)->roots ?? [];
            $inherits = CanonicalJson::stringify($roots) === CanonicalJson::stringify($typeRoots);
        } catch (CanonicalEncodingException) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-draft');
        }
        if (!$sameBase) {
            StudioProducerError::refuse('conflict', 'studio.authoring/item-layout-conflict', $state->entryRevision());
        }
        if (!$sameModel) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/item-layout-model-mismatch');
        }
        if ($inherits) {
            return ['kind' => 'inherit', 'layout' => null];
        }
        if ($roots === []) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/item-layout-empty');
        }
        $entryId = $state->record?->entry->id()
            ?? ContentStudioProjector::contentEntryId(ContentStudioAuthoringDocuments::draftEntryId($session->key()));
        if ($entryId === null) {
            StudioProducerError::refuse('internal', 'studio.authoring/invalid-identity');
        }
        $locks = $this->lockedBlocks($roots);
        try {
            $layout = $this->compositions->admitItemLayout(
                $context,
                $entryId,
                $definition->id,
                $definition->version,
                $roots,
                $locks,
                $this->catalog->renderableBlockLocks(),
                $base,
            );
        } catch (StudioCompositionLockMismatch) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/unlocked-block');
        } catch (CanonicalEncodingException) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-draft');
        } catch (StudioProjectionRejected) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/projection-rejected');
        } catch (
            StudioPublishedBlueprintMismatch
            | StudioPublishedModelMismatch
            | StudioCompositionThemeMismatch
            | StudioPublishedBlockRendererUnavailable
        ) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/item-layout-incompatible');
        }
        $unchanged = $state->itemLayoutActive() && $state->overrides?->itemBlueprintRevision === $layout->revision;

        return ['kind' => $unchanged ? 'unchanged' : 'kept', 'layout' => $layout];
    }

    /**
     * Pin, re-pin or clear the item layout of one saved entry inside the save's transaction.
     *
     * @param   ExecutionContext             $context  Authenticated administrator request.
     * @param   ContentStudioAuthoringState  $state    Live projection the save was planned against.
     * @param   ContentRecord                $record   Record the save wrote.
     * @param   ?StoredStudioArtifact        $layout   Admitted item layout to keep, or null to clear the pointer.
     * @param   string                       $reason   `kept`, `inherited` or `promoted`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function keepItemLayout(
        ExecutionContext $context,
        ContentStudioAuthoringState $state,
        ContentRecord $record,
        ?StoredStudioArtifact $layout,
        string $reason,
    ): void {
        try {
            $this->compositions->keepItemLayout(
                $context,
                $record->entry->id(),
                $record->entry->version(),
                $record->contentTypeVersion,
                $layout,
                $state->overrides,
                $reason,
            );
        } catch (StudioPersistenceRace) {
            StudioProducerError::refuse('conflict', 'studio.authoring/item-layout-conflict', $state->entryRevision());
        }
    }

    /**
     * Record the digest of the reusable type a session is being handed, so its next item save is fenced.
     *
     * @param   ExecutionContext               $context  Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session  Trusted session.
     * @param   ContentStudioAuthoringState    $state    Projection the session is handed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function handed(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringState $state,
    ): void {
        if ($state->type === null) {
            return;
        }
        $digest = self::typeDigest($state->type);
        $this->held(fn () => $this->contexts->rememberHandedType($context, $session->host->resourceId, $digest));
    }

    /**
     * Refuse an item save when the live reusable type differs from the one the session was last handed.
     *
     * While an item keeps its own layout, the type's Blueprint is no longer among the coordinates a plan
     * compares, so a type layout saved elsewhere would otherwise surface only after the save committed. A
     * session that recorded no digest (opened before the fence existed) is not fenced.
     *
     * @param   ExecutionContext               $context  Authenticated administrator request.
     * @param   ContentStudioAuthoringSession  $session  Trusted session.
     * @param   ContentStudioAuthoringState    $state    Live projection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertHandedType(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringState $state,
    ): void {
        if ($state->type === null) {
            return;
        }
        $recorded = $this->held(fn (): ?string => $this->contexts->handedTypeOf($context, $session->host->resourceId));
        if ($recorded !== null && !hash_equals($recorded, self::typeDigest($state->type))) {
            StudioProducerError::refuse('conflict', 'studio.authoring/type-changed', $state->entryRevision());
        }
    }

    /**
     * The digest of one reusable-content-type document as a session is handed it.
     *
     * @param   stdClass  $type  Schema-valid `reusable-content-type` document.
     *
     * @return  string  Lowercase hexadecimal SHA-256 of its canonical JSON.
     *
     * @since   2.0.0
     */
    private static function typeDigest(stdClass $type): string
    {
        return hash('sha256', CanonicalJson::stringify($type));
    }

    /**
     * The block types a Blueprint's node tree composes.
     *
     * @param   array<mixed>  $nodes  Root or slot nodes.
     *
     * @return  array<string, true>  Composed block types.
     *
     * @since   2.0.0
     */
    private static function usedBlockTypes(array $nodes): array
    {
        $types = [];
        foreach ($nodes as $node) {
            $type = $node instanceof stdClass ? ($node->type ?? null) : null;
            if (is_string($type)) {
                $types[$type] = true;
            }
            $slots = $node instanceof stdClass ? ($node->slots ?? null) : null;
            foreach ($slots instanceof stdClass ? get_object_vars($slots) : [] as $children) {
                $types += self::usedBlockTypes(is_array($children) ? $children : []);
            }
        }

        return $types;
    }

    /**
     * Read and type-check the draft of one save request.
     *
     * @param   stdClass  $request  Save request.
     * @param   string    $outcome  Outcome the request must carry.
     *
     * @return  stdClass  Draft object.
     *
     * @since   2.0.0
     */
    private function draft(stdClass $request, string $outcome): stdClass
    {
        $draft = $request->draft ?? null;
        if (!$draft instanceof stdClass || ($draft->outcome ?? null) !== $outcome) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-draft');
        }

        return $draft;
    }

    /**
     * Resolve the exact type version a reusable-type reference names.
     *
     * @param   ExecutionContext  $context    Authenticated administrator request.
     * @param   mixed             $reference  Claimed `{id, version, revision}` reference.
     *
     * @return  ContentTypeDefinition  Authorized exact definition.
     *
     * @since   2.0.0
     */
    private function definitionFromReference(ExecutionContext $context, mixed $reference): ContentTypeDefinition
    {
        $id = $reference instanceof stdClass ? ($reference->id ?? null) : null;
        $version = $reference instanceof stdClass ? ($reference->version ?? null) : null;
        $revision = $reference instanceof stdClass ? ($reference->revision ?? null) : null;
        $typeId = is_string($id) ? ContentStudioAuthoringDocuments::contentTypeId($id) : null;
        $typeVersion = is_string($version) ? ContentStudioProjector::contentTypeVersion($version) : null;
        if (
            $typeId === null
            || $typeVersion === null
            || $revision !== ContentStudioProjector::modelRevision($typeVersion)
        ) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-type-reference');
        }
        try {
            return $this->models->contentType($context, $typeId, $typeVersion);
        } catch (ContentModelNotFound) {
            StudioProducerError::refuse('not-found', 'studio.authoring/type-not-found');
        } catch (AuthorizationDenied) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/types-refused');
        }
    }

    /**
     * Derive the reusable-type reference from an entry's model lock, or null for a draft model.
     *
     * @param   mixed  $model  Entry `model` reference.
     *
     * @return  ?stdClass  Type reference, or null when the model is not a persisted type.
     *
     * @since   2.0.0
     */
    private function typeReferenceOf(mixed $model): ?stdClass
    {
        $id = $model instanceof stdClass ? ($model->id ?? null) : null;
        $typeId = is_string($id) ? ContentStudioProjector::contentTypeId($id) : null;
        if ($typeId === null || !$model instanceof stdClass) {
            return null;
        }

        return (object) [
            'id' => ContentStudioAuthoringDocuments::typeId($typeId),
            'version' => $model->version ?? null,
            'revision' => $model->revision ?? null,
        ];
    }

    /**
     * Derive the reusable-type reference from a Model document, or null for a draft model.
     *
     * @param   stdClass  $model  Content-model document.
     *
     * @return  ?stdClass  Type reference, or null when the model is a blank-canvas draft.
     *
     * @since   2.0.0
     */
    private function typeReferenceOfModel(stdClass $model): ?stdClass
    {
        return $this->typeReferenceOf((object) [
            'id' => $model->id ?? null,
            'version' => $model->version ?? null,
            'revision' => $model->revision ?? null,
        ]);
    }

    /**
     * Map a Studio entry document back onto Content identity and data.
     *
     * @param   stdClass  $entry  Entry document.
     * @param   stdClass  $model  Type the values must satisfy.
     *
     * @return  array{0: string, 1: string, 2: array<string, mixed>}  Title, slug and data.
     *
     * @since   2.0.0
     */
    private function contentValues(stdClass $entry, stdClass $model): array
    {
        $values = $entry->values ?? null;
        if (!$values instanceof stdClass) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-values');
        }
        $known = [];
        foreach ($this->dataFields($model) as $key => $field) {
            $known[self::fieldIdentity($field)] = $key;
        }
        $title = $values->title ?? '';
        $slug = $values->slug ?? '';
        if (!is_string($title) || !is_string($slug)) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/invalid-identity');
        }
        $data = [];
        foreach (get_object_vars($values) as $member => $value) {
            if ($member === 'title' || $member === 'slug') {
                continue;
            }
            if (!isset($known[$member])) {
                StudioProducerError::refuse('validation-failed', 'studio.authoring/unknown-field');
            }
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            $data[$known[$member]] = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        }

        return [$title, $slug, $data];
    }

    /**
     * Translate a Studio content-model document into the JSON object schema a Content type stores.
     *
     * @param   stdClass  $model  Content-model document.
     *
     * @return  array<string, mixed>  Object schema over the model's data fields.
     *
     * @since   2.0.0
     */
    private function schemaFromModel(stdClass $model): array
    {
        $properties = [];
        $required = [];
        foreach ($this->dataFields($model) as $key => $field) {
            $extensions = $field->extensions ?? null;
            $source = $extensions instanceof stdClass ? ($extensions->{'kumwe.app/source-field'} ?? null) : null;
            $properties[$key] = $this->propertySchema($field, $source instanceof stdClass ? $source : null);
            if (($field->required ?? false) === true) {
                $required[] = $key;
            }
        }
        // Studio models enumerate their fields, so the derived Content schema is closed: the projector
        // refuses an open object because later entries could carry undeclared data.
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Require an exact field identifier before using it as a host storage-map value.
     *
     * @param   stdClass  $field  Schema-admitted model field.
     *
     * @return  string  Studio field identifier.
     *
     * @since   2.0.0
     */
    private static function fieldIdentity(stdClass $field): string
    {
        $id = $field->id ?? null;
        if (!is_string($id)) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-model');
        }

        return $id;
    }

    /**
     * Bind exact authored IDs to Content storage keys without putting host metadata in Content schemas.
     *
     * @param   stdClass  $model  Authorized projection or schema-admitted authored model.
     *
     * @return  array<string, stdClass>  Data fields indexed by their immutable Content storage key.
     *
     * @since   2.0.0
     */
    private function dataFields(stdClass $model): array
    {
        $fields = $model->fields ?? null;
        if (!is_array($fields)) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-model');
        }
        $data = [];
        $seen = [];
        foreach ($fields as $field) {
            $id = $field instanceof stdClass ? ($field->id ?? null) : null;
            if (!is_string($id) || isset($seen[$id])) {
                StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-model');
            }
            $seen[$id] = true;
            $extensions = $field->extensions ?? null;
            $source = $extensions instanceof stdClass ? ($extensions->{'kumwe.app/source-field'} ?? null) : null;
            $storage = $source instanceof stdClass ? ($source->storage ?? 'data') : 'data';
            if ($storage === 'entry') {
                if (
                    !in_array($id, ['title', 'slug'], true)
                    || !$source instanceof stdClass || ($source->key ?? null) !== $id
                ) {
                    StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-model');
                }
                continue;
            }
            if ($storage !== 'data' || in_array($id, ['title', 'slug'], true)) {
                StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-model');
            }
            $key = $source instanceof stdClass ? ($source->key ?? null) : $id;
            // Studio local names also allow punctuation and longer IDs than Content storage keys.
            // The durable binding preserves that exact ID while the host allocates its storage name.
            if ($source === null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $id) !== 1) {
                $key = 'studio_' . substr(hash('sha256', $id), 0, 56);
            }
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1 || isset($data[$key])) {
                StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-model');
            }
            $data[$key] = $field;
        }

        return $data;
    }

    /**
     * Translate one Studio field into its Content property schema.
     *
     * @param   stdClass   $field   Content-model field document.
     * @param   ?stdClass  $source  Round-trip source annotation, when the field was projected by App.
     *
     * @return  array<string, mixed>  Property schema.
     *
     * @since   2.0.0
     */
    private function propertySchema(stdClass $field, ?stdClass $source): array
    {
        $carried = $source === null ? null : ($source->schema ?? null);
        if ($carried instanceof stdClass) {
            $decoded = json_decode(json_encode($carried, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && is_string($decoded['type'] ?? null)) {
                /** @var array<string, mixed> $decoded */
                return $this->titled($decoded, $field);
            }
        }
        $kind = $field->kind ?? null;
        $cardinality = $field->cardinality ?? 'one';
        $schema = match ($kind) {
            'string', 'rich-text' => ['type' => 'string'],
            'integer' => ['type' => 'integer'],
            'decimal' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'date-time' => ['type' => 'string', 'format' => 'date-time'],
            'media' => ['type' => 'string', 'x-kumwe-field' => 'media'],
            'enum' => ['type' => 'string', 'enum' => $this->enumValues($field)],
            default => StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-field'),
        };
        $constraints = $field->constraints ?? null;
        if ($constraints instanceof stdClass) {
            foreach (['minLength', 'maxLength'] as $name) {
                if (is_int($constraints->{$name} ?? null)) {
                    $schema[$name] = $constraints->{$name};
                }
            }
            foreach (['minimum', 'maximum'] as $name) {
                $bound = $constraints->{$name} ?? null;
                if (is_string($bound) && is_numeric($bound)) {
                    $schema[$name] = str_contains($bound, '.') ? (float) $bound : (int) $bound;
                }
            }
        }
        if ($cardinality === 'many') {
            $schema = ['type' => 'array', 'items' => $schema];
        }

        return $this->titled($schema, $field);
    }

    /**
     * Carry a field's label and description into its property schema.
     *
     * @param   array<string, mixed>  $schema  Property schema.
     * @param   stdClass              $field   Content-model field document.
     *
     * @return  array<string, mixed>  Property schema with title and description.
     *
     * @since   2.0.0
     */
    private function titled(array $schema, stdClass $field): array
    {
        $label = $field->label ?? null;
        $title = $label instanceof stdClass ? ($label->defaultMessage ?? null) : null;
        if (is_string($title) && trim($title) !== '') {
            $schema['title'] = trim($title);
        }
        $description = $field->description ?? null;
        $text = $description instanceof stdClass ? ($description->defaultMessage ?? null) : null;
        if (is_string($text) && trim($text) !== '') {
            $schema['description'] = trim($text);
        }

        return $schema;
    }

    /**
     * Read the enumerated identifiers of one enum field.
     *
     * @param   stdClass  $field  Content-model field document.
     *
     * @return  list<string>  Enumerated values.
     *
     * @since   2.0.0
     */
    private function enumValues(stdClass $field): array
    {
        $values = [];
        foreach (is_array($field->enumValues ?? null) ? $field->enumValues : [] as $member) {
            $value = $member instanceof stdClass ? ($member->value ?? null) : null;
            if (!is_string($value)) {
                StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-field');
            }
            $values[] = $value;
        }
        if ($values === []) {
            StudioProducerError::refuse('validation-failed', 'studio.authoring/unsupported-field');
        }

        return $values;
    }

    /**
     * The breaking differences between a type's stored schema and a proposed successor.
     *
     * @param   ExecutionContext       $context     Authenticated administrator request.
     * @param   ContentTypeDefinition  $definition  Current type version.
     * @param   stdClass               $model       Proposed successor model.
     *
     * @return  list<string>  Breaking-change descriptions, empty when compatible.
     *
     * @since   2.0.0
     */
    private function breakingChanges(
        ExecutionContext $context,
        ContentTypeDefinition $definition,
        stdClass $model,
    ): array {
        $current = $definition->schema();
        $proposed = $this->schemaFromModel($model);
        $currentRequired = is_array($current['required'] ?? null) ? $current['required'] : [];
        $proposedProperties = is_array($proposed['properties'] ?? null) ? $proposed['properties'] : [];
        $currentProperties = is_array($current['properties'] ?? null) ? $current['properties'] : [];
        $breaking = [];
        foreach ($currentProperties as $key => $currentProperty) {
            $proposedProperty = $proposedProperties[$key] ?? null;
            if ($proposedProperty === null) {
                $breaking[] = 'removed:' . $key;
                continue;
            }
            $currentType = is_array($currentProperty) ? ($currentProperty['type'] ?? null) : null;
            $proposedType = is_array($proposedProperty) ? ($proposedProperty['type'] ?? null) : null;
            if ($currentType !== $proposedType) {
                $breaking[] = 'retyped:' . $key;
            }
        }
        $proposedRequired = is_array($proposed['required'] ?? null) ? $proposed['required'] : [];
        foreach ($proposedRequired as $key) {
            if (is_string($key) && !in_array($key, $currentRequired, true)) {
                $breaking[] = 'required:' . $key;
            }
        }

        return $breaking;
    }

    /**
     * A human name for a model or label document, falling back to a default.
     *
     * @param   stdClass  $document  Document carrying a `label` or being a message reference.
     * @param   string    $default   Name when the document carries none.
     *
     * @return  string  Bounded name.
     *
     * @since   2.0.0
     */
    private function modelName(stdClass $document, string $default): string
    {
        $label = $document->label ?? $document;
        $name = $label instanceof stdClass ? ($label->defaultMessage ?? null) : null;
        $name = is_string($name) && trim($name) !== '' ? trim($name) : $default;

        return mb_substr($name, 0, 255);
    }

    /**
     * Derive a site-unique lowercase handle from a human name.
     *
     * @param   ExecutionContext  $context  Authenticated administrator request.
     * @param   string            $name     Human name.
     *
     * @return  string  Handle no existing type of the site uses.
     *
     * @since   2.0.0
     */
    private function uniqueHandle(ExecutionContext $context, string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');
        if ($base === '' || preg_match('/^[a-z]/', $base) !== 1) {
            $base = 'studio-' . ltrim($base, '-');
        }
        $base = substr(rtrim($base, '-'), 0, 80);
        $taken = [];
        try {
            foreach ($this->models->contentTypes($context) as $definition) {
                $taken[$definition->handle] = true;
            }
        } catch (AuthorizationDenied) {
            StudioProducerError::refuse('forbidden', 'studio.authoring/types-refused');
        }
        $handle = $base;
        for ($suffix = 2; isset($taken[$handle]); $suffix++) {
            $handle = $base . '-' . $suffix;
        }

        return $handle;
    }

    /**
     * The label of one Content type as a Studio message reference.
     *
     * @param   ContentTypeDefinition  $definition  Type version.
     *
     * @return  stdClass  Message reference.
     *
     * @since   2.0.0
     */
    private function typeLabel(ContentTypeDefinition $definition): stdClass
    {
        return ContentStudioAuthoringDocuments::message(
            'kumwe.content/type-' . substr(hash('sha256', $definition->handle), 0, 32),
            $definition->name,
        );
    }

    /**
     * The destination Studio names on its return control, in the interface locale.
     *
     * Studio renders a host message reference by its default text, so the host hands it the text already
     * resolved in the locale of the request; the key stays stable so the pointer names the same resource.
     *
     * @return  stdClass  Schema-valid `messageReference` naming the Content editor.
     *
     * @since   2.0.0
     */
    private function returnLabel(): stdClass
    {
        return ContentStudioAuthoringDocuments::message(
            'kumwe.app/return-to-content',
            $this->translator->translate('core.administrator.content_form.studio_return_destination'),
        );
    }

    /**
     * The locked Blueprint reference one type version resolves to.
     *
     * @param   ExecutionContext       $context     Authenticated administrator request.
     * @param   ContentTypeDefinition  $definition  Type version.
     *
     * @return  stdClass  `{id, version, revision}` reference.
     *
     * @since   2.0.0
     */
    private function blueprintReference(ExecutionContext $context, ContentTypeDefinition $definition): stdClass
    {
        return $this->compositions->reference($context, $definition->id, $definition->version, self::RENDERERS);
    }

    /**
     * The locked Blueprint reference of one provisioned composition.
     *
     * @param   StudioContentComposition  $composition  Provisioned composition.
     *
     * @return  stdClass  `{id, version, revision}` reference.
     *
     * @since   2.0.0
     */
    private static function compositionReference(StudioContentComposition $composition): stdClass
    {
        return (object) [
            'id' => $composition->binding->blueprintId,
            'version' => $composition->binding->blueprintVersion,
            'revision' => $composition->blueprint->revision,
        ];
    }

    /**
     * The provisional entry document of a not-yet-created item.
     *
     * @param   ContentStudioAuthoringSession  $session         Trusted create session.
     * @param   stdClass                       $modelReference  Model the item will satisfy.
     *
     * @return  stdClass  Schema-valid `entry` document with no values.
     *
     * @since   2.0.0
     */
    private function draftEntry(ContentStudioAuthoringSession $session, stdClass $modelReference): stdClass
    {
        return $this->validated('entry', null, (object) [
            'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
            'kind' => 'entry',
            'id' => ContentStudioAuthoringDocuments::draftEntryId($session->key()),
            'revision' => self::DRAFT_ENTRY_REVISION,
            'model' => $modelReference,
            'status' => 'draft',
            'values' => new stdClass(),
        ]);
    }

    /**
     * The two identity fields every Content item carries, as a draft Model declares them.
     *
     * @param   string  $id            Field identifier (`title` or `slug`).
     * @param   string  $label         Human label.
     * @param   string  $semanticRole  Qualified semantic role.
     * @param   int     $order         Stable authoring order.
     * @param   int     $maxLength     Maximum stored length.
     *
     * @return  stdClass  Content-model field document.
     *
     * @since   2.0.0
     */
    private static function entryPropertyField(
        string $id,
        string $label,
        string $semanticRole,
        int $order,
        int $maxLength,
    ): stdClass {
        return (object) [
            'id' => $id,
            'kind' => 'string',
            'label' => ContentStudioAuthoringDocuments::message(
                'kumwe.content/entry-property-' . substr(hash('sha256', $id), 0, 32),
                $label,
            ),
            'required' => true,
            'localized' => true,
            'cardinality' => 'one',
            'semanticRole' => $semanticRole,
            'authoring' => (object) [
                'control' => 'studio.control/single-line-text',
                'group' => 'identity',
                'order' => $order,
                'width' => 'full',
            ],
            'constraints' => (object) ['minLength' => 1, 'maxLength' => $maxLength],
            'extensions' => (object) [
                'kumwe.app/source-field' => (object) ['storage' => 'entry', 'key' => $id],
            ],
        ];
    }

    /**
     * The compiled first-party block locks this deployment admits.
     *
     * @return  list<stdClass>  Locked block references.
     *
     * @since   2.0.0
     */
    private function blockLocks(): array
    {
        return $this->catalog->blockLocks();
    }

    /**
     * The contribution generation every snapshot of this deployment carries.
     *
     * @return  string  Revision derived from the admitted block locks.
     *
     * @since   2.0.0
     */
    private function contributionGeneration(): string
    {
        return $this->catalog->contributionGeneration();
    }

    /**
     * The deterministic plan identity of one outcome against one live state and draft.
     *
     * @param   ContentStudioAuthoringSession  $session  Trusted session.
     * @param   ContentStudioAuthoringState    $state    Live projection.
     * @param   string                         $outcome  Save outcome.
     * @param   stdClass                       $draft    Save draft.
     *
     * @return  string  Stable plan identifier.
     *
     * @since   2.0.0
     */
    private static function planId(
        ContentStudioAuthoringSession $session,
        ContentStudioAuthoringState $state,
        string $outcome,
        stdClass $draft,
    ): string {
        return 'save-plans/' . substr(hash('sha256', CanonicalJson::stringify((object) [
            'coordinates' => $state->coordinates,
            'draft' => hash('sha256', CanonicalJson::stringify($draft)),
            'generation' => $session->generation,
            'outcome' => $outcome,
            'session' => $session->sessionId(),
        ])), 0, 48);
    }

    /**
     * Prove one emitted document against its pinned schema before it leaves PHP.
     *
     * @param   string    $kind        Pinned schema kind.
     * @param   ?string   $definition  Named definition, or null for the document root.
     * @param   stdClass  $document    Candidate document.
     *
     * @return  stdClass  The same document.
     *
     * @since   2.0.0
     */
    private function validated(string $kind, ?string $definition, stdClass $document): stdClass
    {
        $validation = $definition === null
            ? $this->schemas->validate($kind, $document)
            : $this->schemas->validateDefinition($kind, $definition, $document);
        if (!$validation->valid()) {
            StudioProducerError::refuse('internal', 'studio.authoring/document-invalid');
        }

        return $document;
    }
}
