<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringCatalog;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextRefused;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextStale;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Application\Composition\StudioContentDefaultComposition;
use Kumwe\App\Studio\Application\Composition\StudioItemCompositionPolicy;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\Context\Value\ExecutionContext;
use stdClass;

/**
 * Resolves preview values through the existing authorized Content projection without copying authority.
 *
 * A Blueprint session proves direct artifact ownership and carries no entry values. A Content session
 * reuses AP-2 to read the projected model or entry, verifies that model's host-owned Blueprint binding,
 * and exposes only the projected values that survived Content's record and field disclosure policy. A
 * contextual Content authoring session names an opaque authoring context instead of a resource; the
 * context authority re-resolves and re-authorizes the exact create or edit target behind it on every
 * render, so a preview shows the stored item's own values (or no values for an item that is not created
 * yet) and never a substituted entry. Such a session is handed the derived default composition in place of
 * a stored empty draft (App ADR 0024), so its preview is presented that same document. An entry that keeps
 * its own layout (App ADR 0025) previews that exact item layout, accepted only for that entry and only while
 * the item-composition policy allows item layouts.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioPreviewBindingSource implements StudioPreviewBindingSource
{
    /**
     * Bind preview resolution to the read-only authorized Content projection and the context authority.
     *
     * @param  StudioContentProjectionService          $content          Existing App-owned model and entry read
     *         boundary.
     * @param  ContentStudioAuthoringContextAuthority  $contexts         Opaque exact-target authority of contextual
     *         Content authoring sessions.
     * @param  ContentStudioAuthoringCatalog           $catalog          Renderable block locks the authoring session
     *         derives a default composition against (App ADR 0024).
     * @param  StudioItemCompositionPolicy             $itemComposition  The App-wide item-composition policy
     *         an entry's own layout is previewed under (App ADR 0025).
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioContentProjectionService $content,
        private ContentStudioAuthoringContextAuthority $contexts,
        private ContentStudioAuthoringCatalog $catalog,
        private StudioItemCompositionPolicy $itemComposition = new StudioItemCompositionPolicy(),
    ) {
    }

    /**
     * Resolve the session resource and prove its exact model-to-Blueprint coordinate.
     *
     * @param   ExecutionContext           $context   Authenticated App request authority.
     * @param   StudioHostSessionSnapshot  $snapshot  Live resource and permission binding.
     * @param   StudioPreviewDraft         $draft     Exact unpublished Blueprint being rendered.
     *
     * @return  StudioPreviewBindingValues  Canonical values authorized by Content disclosure policy.
     *
     * @throws  StudioPreviewRefused  When the resource cannot be disclosed or is bound elsewhere.
     *
     * @since   2.0.0
     */
    public function resolve(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewDraft $draft,
    ): StudioPreviewBindingValues {
        if ($snapshot->session->resourceKind === StudioResourceKind::Blueprint) {
            if (!hash_equals($snapshot->session->resourceId, $draft->artifactId())) {
                throw new StudioPreviewRefused('forbidden', 'studio.preview/resource-refused');
            }

            return new StudioPreviewBindingValues(new stdClass(), new stdClass());
        }

        try {
            [$model, $values, $itemLayout] = match (true) {
                $snapshot->session->resourceKind === StudioResourceKind::ContentAuthoring
                    => $this->authoring($context, $snapshot->session->resourceId),
                str_starts_with($snapshot->session->resourceId, 'content-entry:')
                    => $this->entry($context, $snapshot->session->resourceId),
                default => $this->model($context, $snapshot->session->resourceId, $draft),
            };
        } catch (StudioProjectionRejected) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/resource-refused');
        }
        $document = $draft->document();
        $draftModel = $document->model ?? null;
        if (!$draftModel instanceof stdClass || !self::sameCoordinate($draftModel, $model)) {
            throw new StudioPreviewRefused('conflict', 'studio.preview/model-binding-mismatch');
        }
        self::assertBlueprintBinding(
            $model,
            $document,
            $this->itemComposition->allowsItemLayouts() ? $itemLayout : null,
        );

        return new StudioPreviewBindingValues($values, new stdClass());
    }

    /**
     * Return the draft a contextual Content authoring session was handed for one stored Blueprint revision.
     *
     * Only a contextual Content authoring session is handed a derived default, and only for a stored empty
     * draft whose model lock is the session target's projected model. Every other session, a refused or
     * unprojectable target, and a model mismatch return `$draft` unchanged, so the caller's identity check
     * refuses exactly as it would without presentation.
     *
     * @param   ExecutionContext           $context   Authenticated App request authority.
     * @param   StudioHostSessionSnapshot  $snapshot  Live resource and permission binding.
     * @param   StudioPreviewDraft         $draft     Stored Blueprint revision the request names.
     *
     * @return  StudioPreviewDraft  A draft carrying the derived default composition, or `$draft` itself.
     *
     * @since   2.0.0
     */
    public function present(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewDraft $draft,
    ): StudioPreviewDraft {
        if ($snapshot->session->resourceKind !== StudioResourceKind::ContentAuthoring) {
            return $draft;
        }
        try {
            [$model] = $this->authoring($context, $snapshot->session->resourceId);
        } catch (StudioProjectionRejected | StudioPreviewRefused) {
            return $draft;
        }
        $document = $draft->document();
        $draftModel = $document->model ?? null;
        if (!$draftModel instanceof stdClass || !self::sameCoordinate($draftModel, $model)) {
            return $draft;
        }
        $presented = StudioContentDefaultComposition::presented(
            $document,
            $model,
            $this->catalog->renderableBlockLocks(),
        );

        return $presented === $document ? $draft : new StudioPreviewDraft($draft->siteIdentifier, $presented);
    }

    /**
     * Resolve the exact target behind one contextual Content authoring session.
     *
     * A stale binding (the actor's approval generation moved since the mount) is followed rather than
     * refused: a preview is a read that must show the live accepted target, and the authority has
     * already re-authorized that target before reporting it as stale. A refused context, and a blank
     * canvas whose reusable type does not exist yet, cannot be previewed.
     *
     * @param   ExecutionContext  $context     Authenticated App request authority.
     * @param   string            $contextKey  Opaque authoring context key the host session is bound to.
     *
     * @return  array{0: stdClass, 1: stdClass, 2: ?stdClass}  Projected model, the stored entry values or no
     *          values, and the entry's pinned item layout reference or null.
     *
     * @throws  StudioPreviewRefused  When the context is refused or names no persisted type.
     * @throws  StudioProjectionRejected  When Content refuses or cannot project the target.
     *
     * @since   2.0.0
     */
    private function authoring(ExecutionContext $context, string $contextKey): array
    {
        try {
            $target = $this->contexts->resolve($context, $contextKey);
        } catch (ContentStudioAuthoringContextStale $stale) {
            $target = $stale->current;
        } catch (ContentStudioAuthoringContextRefused) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/resource-refused');
        }
        if ($target->entryId !== null) {
            return $this->entry($context, $target->entryId);
        }

        return [$this->targetModel($context, $target), new stdClass(), null];
    }

    /**
     * Project the exact reusable type a create target names, refusing a blank canvas.
     *
     * @param   ExecutionContext              $context  Authenticated App request authority.
     * @param   ContentStudioAuthoringTarget  $target   Trusted create target.
     *
     * @return  stdClass  Projected content-model document.
     *
     * @throws  StudioPreviewRefused  When the target has no persisted reusable type to render.
     *
     * @since   2.0.0
     */
    private function targetModel(ExecutionContext $context, ContentStudioAuthoringTarget $target): stdClass
    {
        if ($target->modelId === null || $target->modelVersion === null) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/resource-refused');
        }

        return $this->content->model($context, $target->modelId, $target->modelVersion);
    }

    /**
     * Project a Content entry and its exact pinned model through App authority.
     *
     * @param   ExecutionContext  $context  Authenticated App request authority.
     * @param   string            $entryId  Reversible projected Content entry identifier.
     *
     * @return  array{0: stdClass, 1: stdClass, 2: ?stdClass}  Exact projected model coordinate, authorized values,
     *          and the `{id, version, revision}` of the entry's pinned item layout, or null when it follows its type.
     *
     * @throws  StudioProjectionRejected  When Content refuses or cannot project the entry.
     *
     * @since   2.0.0
     */
    private function entry(ExecutionContext $context, string $entryId): array
    {
        $entry = $this->content->entry($context, $entryId);
        $model = $entry->model ?? null;
        $values = $entry->values ?? null;
        if (!$model instanceof stdClass || !$values instanceof stdClass) {
            throw new StudioPreviewRefused('unavailable', 'studio.preview/content-projection-invalid');
        }
        $id = $model->id ?? null;
        $version = $model->version ?? null;
        if (!is_string($id) || !is_string($version)) {
            throw new StudioPreviewRefused('unavailable', 'studio.preview/content-projection-invalid');
        }

        $extensions = $entry->extensions ?? null;
        $override = $extensions instanceof stdClass
            ? ($extensions->{'kumwe.app/composition-override'} ?? null)
            : null;
        $itemLayout = $override instanceof stdClass ? ($override->blueprint ?? null) : null;

        return [
            $this->content->model($context, $id, $version),
            $values,
            $itemLayout instanceof stdClass ? $itemLayout : null,
        ];
    }

    /**
     * Project a model-bound preview that intentionally has no entry values yet.
     *
     * @param   ExecutionContext    $context  Authenticated App request authority.
     * @param   string              $modelId  Reversible projected Content model identifier.
     * @param   StudioPreviewDraft  $draft    Blueprint whose exact model version is requested.
     *
     * @return  array{0: stdClass, 1: stdClass, 2: null}  Projected model, empty entry values and no item layout.
     *
     * @throws  StudioProjectionRejected  When Content refuses or cannot project the model.
     *
     * @since   2.0.0
     */
    private function model(ExecutionContext $context, string $modelId, StudioPreviewDraft $draft): array
    {
        $model = $draft->document()->model ?? null;
        $draftModelId = $model instanceof stdClass ? $model->id ?? null : null;
        if (!$model instanceof stdClass || !is_string($draftModelId) || !hash_equals($draftModelId, $modelId)) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/resource-refused');
        }
        $version = $model->version ?? null;
        if (!is_string($version)) {
            throw new StudioPreviewRefused('conflict', 'studio.preview/model-binding-mismatch');
        }

        return [$this->content->model($context, $modelId, $version), new stdClass(), null];
    }

    /**
     * Compare a Blueprint model lock with the authoritative projected model coordinate.
     *
     * @param   stdClass  $draftModel      Model coordinate locked by the Blueprint.
     * @param   stdClass  $projectedModel  Authoritative projected Content model.
     *
     * @return  bool  True only when ID, version, and revision agree byte for byte.
     *
     * @since   2.0.0
     */
    private static function sameCoordinate(stdClass $draftModel, stdClass $projectedModel): bool
    {
        foreach (['id', 'version', 'revision'] as $member) {
            $left = $draftModel->{$member} ?? null;
            $right = $projectedModel->{$member} ?? null;
            if (!is_string($left) || !is_string($right) || !hash_equals($left, $right)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prove the authoritative model, or the entry's own pinned item layout, selected this exact Blueprint.
     *
     * @param   stdClass   $model       Authoritative projected Content model.
     * @param   stdClass   $blueprint   Exact Blueprint document being rendered.
     * @param   ?stdClass  $itemLayout  `{id, version, revision}` of the entry's pinned item layout, or null when it
     *          has none or the item-composition policy does not allow item layouts.
     *
     * @return  void
     *
     * @throws  StudioPreviewRefused  When no exact binding exists.
     *
     * @since   2.0.0
     */
    private static function assertBlueprintBinding(stdClass $model, stdClass $blueprint, ?stdClass $itemLayout): void
    {
        if ($itemLayout !== null) {
            $pinned = true;
            foreach (['id', 'version', 'revision'] as $member) {
                $expected = $itemLayout->{$member} ?? null;
                $actual = $blueprint->{$member} ?? null;
                if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
                    $pinned = false;
                }
            }
            if ($pinned) {
                return;
            }
        }
        $extensions = $model->extensions ?? null;
        $binding = $extensions instanceof stdClass ? $extensions->{'kumwe.app/blueprint-binding'} ?? null : null;
        if (!$binding instanceof stdClass) {
            throw new StudioPreviewRefused('conflict', 'studio.preview/model-binding-mismatch');
        }
        foreach (['id', 'version'] as $member) {
            $expected = $blueprint->{$member} ?? null;
            $actual = $binding->{$member} ?? null;
            if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
                throw new StudioPreviewRefused('conflict', 'studio.preview/model-binding-mismatch');
            }
        }
        $boundRevision = $binding->revision ?? null;
        if ($boundRevision !== null) {
            $revision = $blueprint->revision ?? null;
            if (!is_string($boundRevision) || !is_string($revision) || !hash_equals($revision, $boundRevision)) {
                throw new StudioPreviewRefused('conflict', 'studio.preview/model-binding-mismatch');
            }
        }
    }
}
