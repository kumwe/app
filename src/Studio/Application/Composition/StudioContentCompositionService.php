<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Audit\Application\AuditRecorder;
use Kumwe\Audit\Domain\AuditEvent;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Preview\StudioPublishedBlockRendererUnavailable;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\Producer\Canonical\CanonicalEncodingException;
use Kumwe\Producer\Canonical\CanonicalJson;
use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use stdClass;

/**
 * Idempotently provisions the host-owned Blueprint for one authorized Content type version.
 *
 * The same owner keeps an entry's own item layout (App ADR 0025): a full Blueprint snapshot stored as an
 * immutable Studio artifact beside the type's, pinned by exact revision from the entry's composition
 * override record, and written only by an audited item save.
 *
 * @since  2.0.0
 */
final readonly class StudioContentCompositionService
{
    /**
     * Renderer capabilities the App preview runtime implements, which every provisioning surface declares.
     *
     * The administrator composition screen and the REST, console and MCP provisioning operations all pass this
     * one set, so a Blueprint provisioned from any surface is admitted against the same renderers.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array RENDERERS = ['core.renderer/field', 'core.renderer/layout'];

    /**
     * The App-wide item-composition policy every Content type declares by default (App ADR 0025).
     *
     * `overrides` lets Save item keep an entry's own layout. The one shared `StudioItemCompositionPolicy`
     * carries it to every reader; setting it to `denied` is the rollback: saves refuse an item layout again,
     * and the editor, preview and public page ignore stored item layouts, which are kept byte for byte.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ITEM_COMPOSITION = 'overrides';

    /**
     * The one artifact version every item layout Blueprint carries for its entry's life.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ITEM_BLUEPRINT_VERSION = '1.0.0';

    /**
     * Extension member naming the type Blueprint reference an item layout was made from.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ITEM_EXTENSION = 'kumwe.app/item-composition';

    /**
     * Bind the exact projection, write stores, admission, lifecycle, audit, and contribution seams.
     *
     * @param  StudioContentProjectionService        $projection     Authorized AP-2 projection service.
     * @param  ContentProjectionBindingRepository    $bindings       Read-only host binding projection.
     * @param  ContentBlueprintBindingStore          $bindingStore   Write-only initial binding store.
     * @param  StudioArtifactAdmission               $admission      AP-4 canonical artifact admission.
     * @param  StudioArtifactRepository              $artifacts      AP-4 immutable artifact repository.
     * @param  TransactionManager                    $transactions   Atomic persistence coordinator.
     * @param  AuditRecorder                         $audit          Safe audit recorder.
     * @param  ClockInterface                        $clock          Audit event clock.
     * @param  StudioCompositionContributionCatalog  $contributions  Active trusted document catalogue.
     * @param  StudioPublishedTheme                  $theme          Exact published public-theme projection.
     * @param  EntryCompositionOverrideStore         $overrideStore  Write-only item layout pointer store.
     * @param  StudioPublishedCompositionGuard       $guard          Publication guard an item layout must pass
     *         before it is saved.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioContentProjectionService $projection,
        private ContentProjectionBindingRepository $bindings,
        private ContentBlueprintBindingStore $bindingStore,
        private StudioArtifactAdmission $admission,
        private StudioArtifactRepository $artifacts,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private StudioCompositionContributionCatalog $contributions,
        private StudioPublishedTheme $theme,
        private EntryCompositionOverrideStore $overrideStore,
        private StudioPublishedCompositionGuard $guard,
    ) {
    }

    /**
     * Whether the App-wide item-composition policy lets an entry keep its own layout.
     *
     * Every reader of stored item layouts consults this one decision through the shared
     * `StudioItemCompositionPolicy`, so the rollback is that one injected policy.
     *
     * @param   string  $policy  Item-composition policy to decide, the App-wide one by default.
     *
     * @return  bool  True only for the `overrides` policy.
     *
     * @since   2.0.0
     */
    public static function itemLayoutsAllowed(string $policy = self::ITEM_COMPOSITION): bool
    {
        return $policy === 'overrides';
    }

    /**
     * Find an already provisioned composition; this method performs no writes.
     *
     * @param   ExecutionContext  $context             Authorized actor and site context.
     * @param   string            $contentTypeId       Exact Content type UUID.
     * @param   int               $contentTypeVersion  Exact published Content type version.
     *
     * @return  ?StudioContentComposition  Current exact composition, or null when not provisioned.
     *
     * @throws  \Kumwe\App\Studio\Application\Projection\StudioProjectionRejected  When the Content type version
     *          is invalid, absent or not readable by the caller.
     * @throws  StudioCompositionModelMismatch  When the Blueprint model lock differs from the authorized model.
     * @throws  StudioCompositionThemeMismatch  When the Blueprint theme lock differs from the published theme.
     *
     * @since   2.0.0
     */
    public function find(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
    ): ?StudioContentComposition {
        $model = $this->authorizedModel($context, $contentTypeId, $contentTypeVersion);
        $binding = $this->bindings->blueprint($context->site(), $contentTypeId, $contentTypeVersion);
        if ($binding === null) {
            return null;
        }
        $artifact = $binding->blueprintRevision === null
            ? $this->artifacts->current(
                $context->site()->identifier(),
                $binding->blueprintId,
                $binding->blueprintVersion,
            )
            : $this->artifacts->revision(
                $context->site()->identifier(),
                $binding->blueprintId,
                $binding->blueprintVersion,
                $binding->blueprintRevision,
            );
        if ($artifact === null || $artifact->kind !== 'blueprint') {
            throw new RuntimeException('The selected Studio Blueprint is unavailable.');
        }
        $document = $artifact->document();
        $lockedModel = $document->model ?? null;
        if (!self::matchesModel($model, $lockedModel)) {
            throw new StudioCompositionModelMismatch();
        }
        $dependencyLock = $document->dependencyLock ?? null;
        $lockedTheme = $dependencyLock instanceof stdClass ? $dependencyLock->theme ?? null : null;
        if (!$this->theme->reference($context->site())->matches($lockedTheme)) {
            throw new StudioCompositionThemeMismatch();
        }

        return new StudioContentComposition($model, $binding, $artifact);
    }

    /**
     * Provision the derived default draft and binding atomically, returning a concurrent winner.
     *
     * The draft composes the default layout derived from the type version's Content model (App ADR 0024),
     * or no roots when nothing in the model can be composed from the deployment's locked blocks.
     *
     * @param   ExecutionContext  $context             Authorized actor and site context.
     * @param   string            $contentTypeId       Exact Content type UUID.
     * @param   int               $contentTypeVersion  Exact published Content type version.
     * @param   list<string>      $renderers           Deployment-supported renderer capabilities.
     *
     * @return  StudioContentComposition  Newly admitted composition or the concurrent winner.
     *
     * @since   2.0.0
     */
    public function provision(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
        array $renderers,
    ): StudioContentComposition {
        $existing = $this->find($context, $contentTypeId, $contentTypeVersion);
        if ($existing !== null) {
            return $existing;
        }
        $model = $this->authorizedModel($context, $contentTypeId, $contentTypeVersion);
        $binding = new ContentBlueprintBinding(
            $context->site(),
            strtolower($contentTypeId),
            $contentTypeVersion,
            self::blueprintId($contentTypeId, $contentTypeVersion),
            '1.0.0',
            null,
            1,
        );
        $blockLocks = $this->contributions->project([], $renderers)->blockLocks;
        $theme = $this->theme->reference($context->site());
        $roots = StudioContentDefaultComposition::roots($model, $blockLocks);
        $artifact = $this->admission->admit(
            $context->site()->identifier(),
            self::initialBlueprint($model, $binding, $blockLocks, $theme, $roots),
        );

        try {
            $this->transactions->transactional(function () use ($context, $binding, $artifact): void {
                $winner = $this->bindings->blueprint(
                    $context->site(),
                    $binding->contentTypeId,
                    $binding->contentTypeVersion,
                );
                if ($winner !== null) {
                    throw new StudioPersistenceRace('A Content composition was concurrently provisioned.');
                }
                if (!$this->artifacts->store($artifact, null)) {
                    throw new StudioPersistenceRace('A Studio Blueprint was concurrently provisioned.');
                }
                $this->bindingStore->add($binding);
                $this->audit->record(new AuditEvent(
                    Uuid::uuid7()->toString(),
                    $this->clock->now(),
                    $context->actorId(),
                    'studio.composition.provision',
                    'content_type',
                    $binding->contentTypeId,
                    'success',
                    [
                        'binding_revision' => 1,
                        'blueprint_identity_digest' => hash('sha256', $binding->blueprintId),
                        'content_type_version' => $binding->contentTypeVersion,
                        'site_identifier' => $binding->site->identifier(),
                    ],
                ));
            });
        } catch (StudioPersistenceRace) {
            $winner = $this->find($context, $contentTypeId, $contentTypeVersion);
            if ($winner !== null) {
                return $winner;
            }
            throw new RuntimeException('The concurrent Studio composition could not be resolved.');
        }

        // Project the model again so it names the binding just stored, exactly as every later read answers it.
        return new StudioContentComposition(
            $this->authorizedModel($context, $contentTypeId, $contentTypeVersion),
            $binding,
            $artifact,
        );
    }

    /**
     * The locked Blueprint reference one Content type version resolves to, without writing anything.
     *
     * A provisioned composition answers with its exact current revision; a version that has never been
     * provisioned answers with the deterministic identity and revision `provision()` would mint for it,
     * so a catalogue can name the Blueprint a later start will find.
     *
     * @param   ExecutionContext  $context             Authorized actor and site context.
     * @param   string            $contentTypeId       Exact Content type UUID.
     * @param   int               $contentTypeVersion  Exact published Content type version.
     * @param   list<string>      $renderers           Deployment-supported renderer capabilities.
     *
     * @return  stdClass  `{id, version, revision}` locked Blueprint reference.
     *
     * @throws  StudioCompositionModelMismatch  When a provisioned Blueprint no longer locks the authorized model.
     * @throws  StudioCompositionThemeMismatch  When a provisioned Blueprint no longer locks the published theme.
     *
     * @since   2.0.0
     */
    public function reference(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
        array $renderers,
    ): stdClass {
        $existing = $this->find($context, $contentTypeId, $contentTypeVersion);
        if ($existing !== null) {
            return (object) [
                'id' => $existing->binding->blueprintId,
                'version' => $existing->binding->blueprintVersion,
                'revision' => $existing->blueprint->revision,
            ];
        }
        $model = $this->authorizedModel($context, $contentTypeId, $contentTypeVersion);
        $binding = new ContentBlueprintBinding(
            $context->site(),
            strtolower($contentTypeId),
            $contentTypeVersion,
            self::blueprintId($contentTypeId, $contentTypeVersion),
            '1.0.0',
            null,
            1,
        );
        $blockLocks = $this->contributions->project([], $renderers)->blockLocks;
        $initial = self::initialBlueprint(
            $model,
            $binding,
            $blockLocks,
            $this->theme->reference($context->site()),
            StudioContentDefaultComposition::roots($model, $blockLocks),
        );

        return (object) ['id' => $initial->id, 'version' => $initial->version, 'revision' => $initial->revision];
    }

    /**
     * Bind one authored Blueprint to a Content type version that has no composition yet.
     *
     * This is how a reusable-type save outcome persists the layout an author composed in Studio: the
     * document's identity, revision, lock and lifecycle status are rewritten to the host-owned
     * coordinates of the new type version, every locked block must be one this deployment renders, and
     * the artifact, binding and audit event commit together. An already-bound version is refused: a
     * published Blueprint revision never changes in place.
     *
     * @param   ExecutionContext            $context             Authorized actor and site context.
     * @param   string                      $contentTypeId       Exact Content type UUID.
     * @param   int                         $contentTypeVersion  Exact published Content type version.
     * @param   stdClass                    $blueprint           Authored Blueprint document (roots and locks).
     * @param list<stdClass> $admittedLocks Every exact `{type, version, revision}` lock the authoring
     *          session offered; the authored lock must be a subset at identical coordinates.
     * @param   string                      $status              Lifecycle status to store, `draft` or `published`.
     * @param ?string $predecessor Blueprint identity of the type version this one succeeds,
     *          or null for a new reusable type. A successor keeps its predecessor's Blueprint identity and
     *          takes a version of its own, so the reusable type's Blueprint is one artifact with immutable
     *          successor revisions rather than a new artifact per type version.
     * @param   array<string, string>|null  $fieldIds            Exact authored field IDs indexed by Content data key.
     *
     * @return  StudioContentComposition  Newly admitted composition.
     *
     * @throws  StudioCompositionLockMismatch  When a locked block is not renderable by this deployment.
     * @throws  \Kumwe\Producer\Error\HostRefusal  When the rewritten document fails artifact admission.
     * @throws  RuntimeException  When the version is already bound or the store refused the artifact.
     *
     * @since   2.0.0
     */
    public function adopt(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
        stdClass $blueprint,
        array $admittedLocks,
        string $status,
        ?string $predecessor = null,
        ?array $fieldIds = null,
    ): StudioContentComposition {
        if ($this->find($context, $contentTypeId, $contentTypeVersion) !== null) {
            throw new RuntimeException('The Content type version already binds a Studio Blueprint.');
        }
        $model = $this->authorizedModel($context, $contentTypeId, $contentTypeVersion);
        $binding = new ContentBlueprintBinding(
            $context->site(),
            strtolower($contentTypeId),
            $contentTypeVersion,
            $predecessor ?? self::blueprintId($contentTypeId, $contentTypeVersion),
            $predecessor === null ? '1.0.0' : $contentTypeVersion . '.0.0',
            null,
            1,
            $fieldIds,
        );
        $theme = $this->theme->reference($context->site());
        $initial = self::initialBlueprint($model, $binding, $admittedLocks, $theme, []);
        $admitted = self::lockMap($initial);
        $document = json_decode(json_encode($blueprint, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        if (!$document instanceof stdClass) {
            throw new RuntimeException('The authored Studio Blueprint is not an object.');
        }
        $lock = $document->dependencyLock ?? null;
        $blocks = $lock instanceof stdClass ? ($lock->blocks ?? null) : null;
        if (!is_array($blocks)) {
            throw new StudioCompositionLockMismatch('dependencyLock');
        }
        foreach ($blocks as $block) {
            $type = $block instanceof stdClass ? ($block->type ?? null) : null;
            $version = $block instanceof stdClass ? ($block->version ?? null) : null;
            $revision = $block instanceof stdClass ? ($block->revision ?? null) : null;
            if (
                !is_string($type)
                || !is_string($version)
                || !is_string($revision)
                || ($admitted[$type] ?? null) !== [$version, $revision]
            ) {
                throw new StudioCompositionLockMismatch(is_string($type) ? $type : 'dependencyLock');
            }
        }
        $initialRevision = $initial->revision;
        if (!is_string($initialRevision)) {
            throw new RuntimeException('The initial Studio Blueprint revision is invalid.');
        }
        $document->contractVersion = '0.1-draft';
        $document->kind = 'blueprint';
        $document->id = $binding->blueprintId;
        $document->version = $binding->blueprintVersion;
        $document->revision = 'authored-' . hash('sha256', implode("\n", [
            $initialRevision,
            $status,
            json_encode($document->roots ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode($blocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]));
        $document->owner = $initial->owner;
        $document->status = $status;
        $document->model = $initial->model;
        $document->dependencyLock = (object) ['theme' => $theme->document(), 'blocks' => $blocks];
        $artifact = $this->admission->admit($context->site()->identifier(), $document);
        $bound = new ContentBlueprintBinding(
            $binding->site,
            $binding->contentTypeId,
            $binding->contentTypeVersion,
            $binding->blueprintId,
            $binding->blueprintVersion,
            $artifact->revision,
            1,
            $fieldIds,
        );
        $this->transactions->transactional(function () use ($context, $bound, $artifact): void {
            $existing = $this->bindings->blueprint($context->site(), $bound->contentTypeId, $bound->contentTypeVersion);
            if ($existing !== null) {
                throw new StudioPersistenceRace('A Content composition was concurrently provisioned.');
            }
            if (!$this->artifacts->store($artifact, null)) {
                throw new StudioPersistenceRace('A Studio Blueprint was concurrently provisioned.');
            }
            $this->bindingStore->add($bound);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $this->clock->now(),
                $context->actorId(),
                'studio.composition.adopt',
                'content_type',
                $bound->contentTypeId,
                'success',
                [
                    'binding_revision' => 1,
                    'blueprint_identity_digest' => hash('sha256', $bound->blueprintId),
                    'blueprint_revision' => $artifact->revision,
                    'content_type_version' => $bound->contentTypeVersion,
                    'site_identifier' => $bound->site->identifier(),
                    'status' => $artifact->status,
                ],
            ));
        });

        return new StudioContentComposition(
            $fieldIds === null ? $model : $this->authorizedModel($context, $contentTypeId, $contentTypeVersion),
            $bound,
            $artifact,
        );
    }

    /**
     * Load the item layout an entry's override record pins, without writing anything.
     *
     * The exact pinned revision is loaded and its identity checked. A layout whose model lock is not the
     * given type version's model was made for another type version, and a layout locked to a theme that is no
     * longer published was made for another theme: either is kept but detached, and null is answered so the
     * entry follows its type's layout until the author saves a layout again, which locks the live theme.
     *
     * @param   ExecutionContext           $context             Authorized actor and site context.
     * @param   string                     $contentTypeId       Exact Content type UUID the entry pins.
     * @param   int                        $contentTypeVersion  Exact Content type version the entry pins.
     * @param   EntryCompositionOverrides  $overrides           The entry's override record.
     *
     * @return  ?StoredStudioArtifact  The pinned item layout, or null when none is pinned or it is detached.
     *
     * @throws  RuntimeException  When the pinned revision is missing or is not the entry's item layout.
     *
     * @since   2.0.0
     */
    public function itemLayout(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
        EntryCompositionOverrides $overrides,
    ): ?StoredStudioArtifact {
        $id = $overrides->itemBlueprintId();
        $revision = $overrides->itemBlueprintRevision;
        if ($id === null || $revision === null) {
            return null;
        }
        $site = $context->site()->identifier();
        if ($overrides->site->identifier() !== $site) {
            throw new RuntimeException('The item layout belongs to another site.');
        }
        $artifact = $this->artifacts->revision($site, $id, self::ITEM_BLUEPRINT_VERSION, $revision);
        if (
            $artifact === null
            || $artifact->kind !== 'blueprint'
            || $artifact->id !== $id
            || $artifact->version !== self::ITEM_BLUEPRINT_VERSION
            || $artifact->revision !== $revision
        ) {
            throw new RuntimeException('The pinned item layout is unavailable.');
        }
        $document = $artifact->document();
        $model = $this->authorizedModel($context, $contentTypeId, $contentTypeVersion);
        if (!self::matchesModel($model, $document->model ?? null)) {
            return null;
        }
        $dependencyLock = $document->dependencyLock ?? null;
        $lockedTheme = $dependencyLock instanceof stdClass ? $dependencyLock->theme ?? null : null;
        if (!$this->theme->reference($context->site())->matches($lockedTheme)) {
            return null;
        }

        return $artifact;
    }

    /**
     * Mint and validate the item layout one entry would keep, without writing anything.
     *
     * The host builds the document; only the roots come from the author. Its identity is the entry's item
     * Blueprint, its status is published, it locks the entry's exact type-version model, the live theme and
     * exactly the given block locks, and it names the type Blueprint reference it was made from. Its revision
     * is a digest of that content, so an equal layout always has the same revision. Every lock must be one the
     * deployment renders at identical coordinates, the document must pass artifact admission, and the
     * publication guard must accept it, so a layout the public page would refuse is refused before it is saved.
     *
     * @param   ExecutionContext  $context             Authorized actor and site context.
     * @param   string            $entryId             Canonical Content entry UUID that keeps the layout.
     * @param   string            $contentTypeId       Exact Content type UUID the entry pins.
     * @param   int               $contentTypeVersion  Exact Content type version the entry pins.
     * @param   list<mixed>       $roots               Authored layout roots.
     * @param   list<stdClass>    $blockLocks          Exact `{type, version, revision}` locks of the composed blocks.
     * @param   list<stdClass>    $admittedLocks       Every lock the deployment renders; each block lock must be one.
     * @param   stdClass          $base                `{id, version, revision}` of the type Blueprint handed to the
     *          session the layout was made in.
     *
     * @return  StoredStudioArtifact  The admitted candidate item layout.
     *
     * @throws  StudioCompositionLockMismatch  When a block lock is not renderable by this deployment.
     * @throws  \Kumwe\Producer\Error\HostRefusal  When the document fails artifact admission.
     * @throws  CanonicalEncodingException  When the roots or locks are not canonical JSON values.
     * @throws  StudioPublishedBlueprintMismatch  When the publication guard refuses the document's schema or owner.
     * @throws  StudioPublishedModelMismatch  When the publication guard cannot reproduce the locked model.
     * @throws  StudioCompositionThemeMismatch  When the live published theme differs from the lock.
     * @throws  StudioPublishedBlockRendererUnavailable  When a locked block or node has no live renderer.
     * @throws  RuntimeException  When the model projection or the base reference lacks an exact coordinate.
     *
     * @since   2.0.0
     */
    public function admitItemLayout(
        ExecutionContext $context,
        string $entryId,
        string $contentTypeId,
        int $contentTypeVersion,
        array $roots,
        array $blockLocks,
        array $admittedLocks,
        stdClass $base,
    ): StoredStudioArtifact {
        $site = $context->site();
        $model = $this->authorizedModel($context, $contentTypeId, $contentTypeVersion);
        $modelReference = self::exactReference($model);
        $baseReference = self::exactReference($base);
        if ($modelReference === null || $baseReference === null) {
            throw new RuntimeException('The item layout coordinates are invalid.');
        }
        $admitted = self::lockMap((object) ['dependencyLock' => (object) ['blocks' => $admittedLocks]]);
        foreach ($blockLocks as $block) {
            $type = $block->type ?? null;
            $version = $block->version ?? null;
            $revision = $block->revision ?? null;
            if (
                !is_string($type)
                || !is_string($version)
                || !is_string($revision)
                || ($admitted[$type] ?? null) !== [$version, $revision]
            ) {
                throw new StudioCompositionLockMismatch(is_string($type) ? $type : 'dependencyLock');
            }
        }
        $locks = CanonicalJson::stringify($blockLocks);
        $composed = CanonicalJson::stringify($roots);
        $theme = $this->theme->reference($site);
        $id = self::itemBlueprintId($entryId);
        $document = (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'blueprint',
            'id' => $id,
            'version' => self::ITEM_BLUEPRINT_VERSION,
            'revision' => 'item-' . hash('sha256', implode("\n", [
                $site->identifier(),
                $id,
                self::ITEM_BLUEPRINT_VERSION,
                $modelReference['revision'],
                $baseReference['id'],
                $baseReference['version'],
                $baseReference['revision'],
                $theme->revision,
                $locks,
                $composed,
            ])),
            'owner' => (object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'],
            'status' => 'published',
            'label' => (object) [
                'key' => 'kumwe.app/content-item-blueprint',
                'defaultMessage' => 'Item layout',
            ],
            'model' => (object) $modelReference,
            'dependencyLock' => (object) [
                'theme' => $theme->document(),
                'blocks' => json_decode($locks, false, 64, JSON_THROW_ON_ERROR),
            ],
            'roots' => json_decode($composed, false, 512, JSON_THROW_ON_ERROR),
            'extensions' => (object) [
                self::ITEM_EXTENSION => (object) ['base' => (object) $baseReference],
            ],
        ];
        $artifact = $this->admission->admit($site->identifier(), $document);
        $this->guard->assertCompatible($site, $artifact->document());

        return $artifact;
    }

    /**
     * Pin, re-pin or clear one entry's item layout inside the item save's transaction, with one audit event.
     *
     * A layout whose revision is not yet in the artifact history is stored by compare-and-set on the item
     * Blueprint head; a revision already stored (an author returning to an earlier layout) is pinned again
     * without storing, because history never accepts a revision twice. The entry's override record is then
     * inserted, or its pointer moved by compare-and-set on its override revision; its override values are
     * never rewritten. Nothing is written or audited when the pointer already names the requested revision.
     * The caller's transaction is joined, so a race dooms the whole save.
     *
     * @param   ExecutionContext            $context             Authorized actor and site context.
     * @param   string                      $entryId             Canonical UUID of the entry the save wrote.
     * @param   int                         $entryVersion        Entry version the save wrote.
     * @param   int                         $contentTypeVersion  Content type version the entry pins after the save.
     * @param   ?StoredStudioArtifact       $layout              Admitted item layout to pin, or null to clear.
     * @param   ?EntryCompositionOverrides  $current             Override record read before the save, or null.
     * @param   string                      $reason              `kept` with a layout; `inherited` or `promoted`
     *          to clear the pointer.
     *
     * @return  void
     *
     * @throws  StudioPersistenceRace  When the item Blueprint head or the override record moved concurrently.
     * @throws  LogicException  When the layout, record or reason does not belong to this entry and site.
     *
     * @since   2.0.0
     */
    public function keepItemLayout(
        ExecutionContext $context,
        string $entryId,
        int $entryVersion,
        int $contentTypeVersion,
        ?StoredStudioArtifact $layout,
        ?EntryCompositionOverrides $current,
        string $reason,
    ): void {
        if (
            !in_array($reason, ['kept', 'inherited', 'promoted'], true)
            || ($layout === null) === ($reason === 'kept')
        ) {
            throw new LogicException('An item layout save names an invalid reason.');
        }
        $site = $context->site();
        $id = self::itemBlueprintId($entryId);
        if (
            $layout !== null
            && (
                $layout->siteIdentifier !== $site->identifier()
                || $layout->kind !== 'blueprint'
                || $layout->id !== $id
                || $layout->version !== self::ITEM_BLUEPRINT_VERSION
                || $layout->status !== 'published'
            )
        ) {
            throw new LogicException('The item layout does not belong to this entry.');
        }
        if (
            $current !== null
            && (
                $current->site->identifier() !== $site->identifier()
                || strtolower($current->entryId) !== strtolower($entryId)
            )
        ) {
            throw new LogicException('The override record does not belong to this entry.');
        }
        $next = $layout?->revision;
        if ($current?->itemBlueprintRevision === $next) {
            return;
        }
        $record = new EntryCompositionOverrides(
            $site,
            $current === null ? $entryId : $current->entryId,
            $current === null ? new stdClass() : $current->values(),
            $current === null ? 1 : $current->revision + 1,
            $next,
        );
        $base = $layout === null ? null : self::itemBase($layout->document());
        $this->transactions->transactional(function () use (
            $context,
            $site,
            $id,
            $entryVersion,
            $contentTypeVersion,
            $layout,
            $current,
            $record,
            $reason,
            $base,
        ): void {
            if (
                $layout !== null
                && $this->artifacts->revision(
                    $site->identifier(),
                    $id,
                    self::ITEM_BLUEPRINT_VERSION,
                    $layout->revision,
                ) === null
            ) {
                $head = $this->artifacts->current($site->identifier(), $id, self::ITEM_BLUEPRINT_VERSION);
                if (!$this->artifacts->store($layout, $head?->revision)) {
                    throw new StudioPersistenceRace('An item layout was concurrently stored.');
                }
            }
            $this->overrideStore->pin($record, $current?->revision);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $this->clock->now(),
                $context->actorId(),
                'studio.composition.item-layout',
                'content_entry',
                $record->entryId,
                'success',
                [
                    'base_blueprint_revision' => $base,
                    'blueprint_identity_digest' => hash('sha256', $id),
                    'blueprint_revision' => $record->itemBlueprintRevision,
                    'content_type_version' => $contentTypeVersion,
                    'entry_version' => $entryVersion,
                    'override_revision' => $record->revision,
                    'reason' => $reason,
                    'site_identifier' => $site->identifier(),
                ],
            ));
        });
    }

    /**
     * Read the type Blueprint revision an item layout document names as the layout it was made from.
     *
     * @param   stdClass  $document  Item layout document.
     *
     * @return  ?string  Base type Blueprint revision, or null when the document names none.
     *
     * @since   2.0.0
     */
    private static function itemBase(stdClass $document): ?string
    {
        $extensions = $document->extensions ?? null;
        $item = $extensions instanceof stdClass ? ($extensions->{self::ITEM_EXTENSION} ?? null) : null;
        $base = $item instanceof stdClass ? ($item->base ?? null) : null;
        $revision = $base instanceof stdClass ? ($base->revision ?? null) : null;

        return is_string($revision) ? $revision : null;
    }

    /**
     * Copy the exact `{id, version, revision}` coordinate of one document or reference.
     *
     * @param   stdClass  $document  Document or reference carrying the coordinate.
     *
     * @return  ?array{id: string, version: string, revision: string}  The three members, or null when a member
     *          is not a non-empty string.
     *
     * @since   2.0.0
     */
    private static function exactReference(stdClass $document): ?array
    {
        $id = $document->id ?? null;
        $version = $document->version ?? null;
        $revision = $document->revision ?? null;
        if (
            !is_string($id)
            || $id === ''
            || !is_string($version)
            || $version === ''
            || !is_string($revision)
            || $revision === ''
        ) {
            return null;
        }

        return ['id' => $id, 'version' => $version, 'revision' => $revision];
    }

    /**
     * Index one Blueprint's admitted block locks by type for exact lock comparison.
     *
     * @param   stdClass  $blueprint  Blueprint whose dependency lock is authoritative.
     *
     * @return  array<string, array{0: string, 1: string}>  Admitted `[version, revision]` pairs by block type.
     *
     * @since   2.0.0
     */
    private static function lockMap(stdClass $blueprint): array
    {
        $lock = $blueprint->dependencyLock ?? null;
        $blocks = $lock instanceof stdClass ? ($lock->blocks ?? null) : null;
        $map = [];
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            $type = $block instanceof stdClass ? ($block->type ?? null) : null;
            $version = $block instanceof stdClass ? ($block->version ?? null) : null;
            $revision = $block instanceof stdClass ? ($block->revision ?? null) : null;
            if (is_string($type) && is_string($version) && is_string($revision)) {
                $map[$type] = [$version, $revision];
            }
        }

        return $map;
    }

    /**
     * Derive the stable host-owned Blueprint identity for one Content type version.
     *
     * @param   string  $contentTypeId       Canonical Content type UUID.
     * @param   int     $contentTypeVersion  Exact published Content type version.
     *
     * @return  string  Stable Blueprint identity.
     *
     * @since   2.0.0
     */
    public static function blueprintId(string $contentTypeId, int $contentTypeVersion): string
    {
        return sprintf('content-blueprint:%s:v%d', strtolower($contentTypeId), $contentTypeVersion);
    }

    /**
     * Derive the stable item layout Blueprint identity of one Content entry.
     *
     * @param   string  $entryId  Canonical Content entry UUID.
     *
     * @return  string  Item Blueprint identity, disjoint from every type Blueprint identity.
     *
     * @since   2.0.0
     */
    public static function itemBlueprintId(string $entryId): string
    {
        return EntryCompositionOverrides::ITEM_BLUEPRINT_PREFIX . strtolower($entryId);
    }

    /**
     * Obtain the authorized exact AP-2 model projection for one type version.
     *
     * @param   ExecutionContext  $context             Authorized actor and site context.
     * @param   string            $contentTypeId       Canonical Content type UUID.
     * @param   int               $contentTypeVersion  Exact published Content type version.
     *
     * @return  stdClass  Authorized exact Content model projection.
     *
     * @since   2.0.0
     */
    private function authorizedModel(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
    ): stdClass {
        return $this->projection->model(
            $context,
            ContentStudioProjector::modelId($contentTypeId),
            ContentStudioProjector::modelVersion($contentTypeVersion),
        );
    }

    /**
     * Compare the immutable Blueprint model lock with the live authorized projection.
     *
     * @param   stdClass  $model      Current authorized Content-model projection.
     * @param   mixed     $candidate  Blueprint's locked model reference.
     *
     * @return  bool  True only when identifier, version, and revision all match exactly.
     *
     * @since   2.0.0
     */
    private static function matchesModel(stdClass $model, mixed $candidate): bool
    {
        $id = $model->id ?? null;
        $version = $model->version ?? null;
        $revision = $model->revision ?? null;

        return $candidate instanceof stdClass
            && is_string($id)
            && is_string($version)
            && is_string($revision)
            && ($candidate->id ?? null) === $id
            && ($candidate->version ?? null) === $version
            && ($candidate->revision ?? null) === $revision;
    }

    /**
     * Build the initial schema-valid draft Blueprint with immutable model, block, and public-theme locks.
     *
     * Initial roots are part of the revision, so a revision names its content; an initial Blueprint with no
     * roots keeps the revision it had before default compositions existed.
     *
     * @param   stdClass                       $model       Exact AP-2 Content model projection.
     * @param   ContentBlueprintBinding        $binding     Initial host-owned binding.
     * @param   list<stdClass>                 $blockLocks  Deployment-renderable exact block locks.
     * @param   StudioPublishedThemeReference  $theme       Exact active public-theme reference.
     * @param   list<stdClass>                 $roots       Initial composition roots, empty for an adopted layout.
     *
     * @return  stdClass  Canonical initial Blueprint document.
     *
     * @throws  RuntimeException  When the authorized model projection lacks an exact coordinate.
     *
     * @since   2.0.0
     */
    private static function initialBlueprint(
        stdClass $model,
        ContentBlueprintBinding $binding,
        array $blockLocks,
        StudioPublishedThemeReference $theme,
        array $roots,
    ): stdClass {
        $modelId = $model->id ?? null;
        $modelVersion = $model->version ?? null;
        $modelRevision = $model->revision ?? null;
        if (
            !is_string($modelId)
            || $modelId === ''
            || !is_string($modelVersion)
            || $modelVersion === ''
            || !is_string($modelRevision)
            || $modelRevision === ''
        ) {
            throw new RuntimeException('The Studio Content-model projection coordinate is invalid.');
        }
        $modelReference = (object) [
            'id' => $modelId,
            'version' => $modelVersion,
            'revision' => $modelRevision,
        ];
        $identity = [
            $binding->site->identifier(),
            $binding->blueprintId,
            $binding->blueprintVersion,
            $modelRevision,
            (string) json_encode($blockLocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $theme->revision,
        ];
        if ($roots !== []) {
            $identity[] = StudioContentDefaultComposition::VERSION . ':'
                . json_encode($roots, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        $revision = 'initial-' . hash('sha256', implode("\n", $identity));

        return (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'blueprint',
            'id' => $binding->blueprintId,
            'version' => $binding->blueprintVersion,
            'revision' => $revision,
            'owner' => (object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'],
            'status' => 'draft',
            'label' => (object) [
                'key' => 'kumwe.app/content-blueprint',
                'defaultMessage' => 'Content composition',
            ],
            'model' => $modelReference,
            'dependencyLock' => (object) [
                'theme' => $theme->document(),
                'blocks' => $blockLocks,
            ],
            'roots' => $roots,
        ];
    }
}
