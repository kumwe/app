<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use stdClass;

/**
 * Idempotently provisions the host-owned Blueprint for one authorized Content type version.
 *
 * @since  2.0.0
 */
final readonly class StudioContentCompositionService
{
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
    ) {
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
     * Provision an empty schema-valid draft and binding atomically, returning a concurrent winner.
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
        $artifact = $this->admission->admit(
            $context->site()->identifier(),
            self::initialBlueprint($model, $binding, $blockLocks, $theme),
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

        return new StudioContentComposition($model, $binding, $artifact);
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
     * Build the empty schema-valid Blueprint with immutable model, block, and public-theme locks.
     *
     * @param   stdClass                       $model       Exact AP-2 Content model projection.
     * @param   ContentBlueprintBinding        $binding     Initial host-owned binding.
     * @param   list<stdClass>                 $blockLocks  Deployment-renderable exact block locks.
     * @param   StudioPublishedThemeReference  $theme       Exact active public-theme reference.
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
        $revision = 'initial-' . hash('sha256', implode("\n", [
            $binding->site->identifier(),
            $binding->blueprintId,
            $binding->blueprintVersion,
            $modelRevision,
            (string) json_encode($blockLocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $theme->revision,
        ]));

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
            'roots' => [],
        ];
    }
}
