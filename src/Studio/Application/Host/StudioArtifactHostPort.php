<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Artifact\StudioArtifactReference;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use stdClass;

/**
 * Complete versioned implementation of Studio's canonical artifact host port.
 *
 * @since  2.0.0
 */
final readonly class StudioArtifactHostPort
{
    /**
     * Bind artifact admission and persistence to the shared mutation executor.
     *
     * @param  StudioArtifactRepository        $artifacts    Versioned artifact persistence port.
     * @param  StudioArtifactAdmission         $admission    Schema and active-content boundary.
     * @param  StudioMutationExecutor          $mutations    Atomic idempotency executor.
     * @param  StudioArtifactPublicationGuard  $publication  Exact public-runtime dependency guard.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioArtifactRepository $artifacts,
        private StudioArtifactAdmission $admission,
        private StudioMutationExecutor $mutations,
        private StudioArtifactPublicationGuard $publication,
    ) {
    }

    /**
     * Dispatch one artifact operation after the common host session fence has succeeded.
     *
     * @param   string                     $operation  Route operation segment.
     * @param   StudioHostRequest          $request    Validated canonical request.
     * @param   StudioHostSessionSnapshot  $snapshot   Trusted live host session.
     *
     * @return  StudioHostResult  Canonical port result.
     *
     * @since   2.0.0
     */
    public function dispatch(
        string $operation,
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
    ): StudioHostResult {
        return match ($operation) {
            'dependencies' => $this->dependencies($request, $snapshot),
            'load' => $this->load($request, $snapshot),
            'publish' => $this->setPublished($request, $snapshot, true),
            'save' => $this->save($request, $snapshot),
            'unpublish' => $this->setPublished($request, $snapshot, false),
            default => throw new StudioHostOperationRefused('incompatible', 'studio.host/operation-unavailable'),
        };
    }

    /**
     * Return the admitted artifact's complete locked dependency set.
     *
     * @param   StudioHostRequest          $request   Validated read request.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     *
     * @return  StudioHostResult  Canonical dependency list.
     *
     * @since   2.0.0
     */
    private function dependencies(
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
    ): StudioHostResult {
        $this->requireReadContext($request);
        $reference = $this->referenceArgument($request);
        $artifact = $this->find($snapshot, $reference);

        return new StudioHostResult($artifact->dependencies());
    }

    /**
     * Load a current or immutable historical artifact revision.
     *
     * @param   StudioHostRequest          $request   Validated read request.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     *
     * @return  StudioHostResult  Canonical artifact document and revision.
     *
     * @since   2.0.0
     */
    private function load(StudioHostRequest $request, StudioHostSessionSnapshot $snapshot): StudioHostResult
    {
        $this->requireReadContext($request);
        $reference = $this->referenceArgument($request);
        $artifact = $this->find($snapshot, $reference);

        return new StudioHostResult($artifact->document(), $artifact->revision);
    }

    /**
     * Append one schema-valid artifact revision under optimistic concurrency.
     *
     * @param   StudioHostRequest          $request   Validated save request.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     *
     * @return  StudioHostResult  Empty success value and generated revision.
     *
     * @since   2.0.0
     */
    private function save(StudioHostRequest $request, StudioHostSessionSnapshot $snapshot): StudioHostResult
    {
        $this->requirePermission($snapshot, 'studio.permission/save');
        $expectedRevision = $this->requireExpectedRevision($request);
        $arguments = $this->exactArguments($request, ['document']);
        $document = $arguments->document;
        $admitted = $this->admission->admit($snapshot->session->siteId, $document);
        $this->requireResource($snapshot, $admitted->id, $admitted->kind);
        if (!hash_equals($expectedRevision, $admitted->revision)) {
            throw new StudioHostOperationRefused(
                'conflict',
                'studio.artifact/revision-conflict',
                $this->safeCurrentRevision($snapshot, $admitted->id, $admitted->version),
            );
        }

        return $this->mutations->execute(
            $snapshot,
            $request,
            $document,
            function () use ($snapshot, $request, $admitted, $expectedRevision): StudioHostResult {
                $current = $this->artifacts->current(
                    $snapshot->session->siteId,
                    $admitted->id,
                    $admitted->version,
                );
                if ($current === null) {
                    throw new StudioHostOperationRefused('not-found', 'studio.artifact/not-found');
                }
                $this->requireResource($snapshot, $current->id, $current->kind);
                if (!hash_equals($current->revision, $expectedRevision)) {
                    throw new StudioHostOperationRefused(
                        'conflict',
                        'studio.artifact/revision-conflict',
                        $current->revision,
                    );
                }
                $this->requireSaveContinuity($current, $admitted);
                $nextRevision = $this->nextRevision($admitted, $request, $expectedRevision);
                $next = $this->admission->revise($admitted, $nextRevision, $current->status);
                if (!$this->artifacts->store($next, $current->revision)) {
                    throw new StudioHostOperationRefused(
                        'conflict',
                        'studio.artifact/revision-conflict',
                        $this->safeCurrentRevision($snapshot, $admitted->id, $admitted->version),
                    );
                }

                return new StudioHostResult(null, $nextRevision);
            },
        );
    }

    /**
     * Keep generic saves inside the current draft's lifecycle, coordinate and Blueprint locks.
     *
     * @param   StoredStudioArtifact  $current    Transactionally read current artifact head.
     * @param   StoredStudioArtifact  $candidate  Schema-admitted save candidate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireSaveContinuity(
        StoredStudioArtifact $current,
        StoredStudioArtifact $candidate,
    ): void {
        if (
            !hash_equals($current->siteIdentifier, $candidate->siteIdentifier)
            || !hash_equals($current->id, $candidate->id)
            || !hash_equals($current->version, $candidate->version)
            || !hash_equals($current->kind, $candidate->kind)
        ) {
            throw new StudioHostOperationRefused(
                'conflict',
                'studio.artifact/coordinate-conflict',
                $current->revision,
            );
        }
        if ($current->status !== 'draft') {
            throw new StudioHostOperationRefused(
                'conflict',
                'studio.artifact/not-draft',
                $current->revision,
            );
        }
        if (!hash_equals($current->status, $candidate->status)) {
            throw new StudioHostOperationRefused(
                'conflict',
                'studio.artifact/lifecycle-change-requires-publish',
                $current->revision,
            );
        }
        if (
            $current->kind === 'blueprint'
            && !hash_equals($this->blueprintLockBytes($current), $this->blueprintLockBytes($candidate))
        ) {
            throw new StudioHostOperationRefused(
                'conflict',
                'studio.artifact/blueprint-lock-conflict',
                $current->revision,
            );
        }
    }

    /**
     * Canonicalize the Blueprint ownership, model and dependency-lock seam as one comparison value.
     *
     * @param   StoredStudioArtifact  $artifact  Schema-admitted Blueprint artifact.
     *
     * @return  string  Canonical bytes for every immutable Blueprint lock.
     *
     * @since   2.0.0
     */
    private function blueprintLockBytes(StoredStudioArtifact $artifact): string
    {
        $document = $artifact->document();

        return CanonicalJson::stringify((object) [
            'dependencyLock' => $document->dependencyLock,
            'model' => $document->model,
            'owner' => $document->owner,
        ]);
    }

    /**
     * Append a publish or unpublish lifecycle revision.
     *
     * @param   StudioHostRequest          $request    Validated lifecycle request.
     * @param   StudioHostSessionSnapshot  $snapshot   Trusted live host session.
     * @param   bool                       $published  Whether the target status is published.
     *
     * @return  StudioHostResult  Empty success value and generated revision.
     *
     * @since   2.0.0
     */
    private function setPublished(
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
        bool $published,
    ): StudioHostResult {
        $this->requireLifecyclePermission($snapshot, $published);
        $expectedRevision = $this->requireExpectedRevision($request);
        $reference = $this->referenceArgument($request);
        $this->requireResourceId($snapshot, $reference->id);

        return $this->mutations->execute(
            $snapshot,
            $request,
            $reference->document(),
            function () use ($snapshot, $request, $reference, $expectedRevision, $published): StudioHostResult {
                $current = $this->artifacts->current(
                    $snapshot->session->siteId,
                    $reference->id,
                    $reference->version,
                );
                if ($current === null) {
                    throw new StudioHostOperationRefused('not-found', 'studio.artifact/not-found');
                }
                $this->requireResource($snapshot, $current->id, $current->kind);
                if (!hash_equals($current->revision, $expectedRevision)) {
                    throw new StudioHostOperationRefused(
                        'conflict',
                        'studio.artifact/revision-conflict',
                        $current->revision,
                    );
                }
                if ($current->status === 'retired') {
                    throw new StudioHostOperationRefused('conflict', 'studio.artifact/retired');
                }
                if ($published && $this->isAppOwnedBlueprint($current)) {
                    try {
                        $this->publication->assertPublishable(
                            SiteContext::fromString($current->siteIdentifier),
                            $current->document(),
                        );
                    } catch (StudioHostOperationRefused $refused) {
                        throw new StudioHostOperationRefused(
                            $refused->category,
                            $refused->diagnosticCode,
                            $current->revision,
                            $refused->retryable,
                            $refused->retryAfterMilliseconds,
                        );
                    }
                }
                $status = $published ? 'published' : 'draft';
                $nextRevision = $this->nextRevision($current, $request, $expectedRevision);
                $next = $this->admission->revise($current, $nextRevision, $status);
                if (!$this->artifacts->store($next, $current->revision)) {
                    throw new StudioHostOperationRefused(
                        'conflict',
                        'studio.artifact/revision-conflict',
                        $this->safeCurrentRevision($snapshot, $current->id, $current->version),
                    );
                }

                return new StudioHostResult(null, $nextRevision);
            },
        );
    }

    /**
     * Select only the host-owned Content composition family governed by the public App renderer.
     *
     * Other Blueprint owners retain their own publication authority and are never interpreted as App
     * Content pages merely because they use the same portable Studio artifact kind.
     *
     * @param   StoredStudioArtifact  $artifact  Current schema-admitted artifact head.
     *
     * @return  bool  True only for the exact App Content owner coordinate.
     *
     * @since   2.0.0
     */
    private function isAppOwnedBlueprint(StoredStudioArtifact $artifact): bool
    {
        if ($artifact->kind !== 'blueprint') {
            return false;
        }
        $owner = $artifact->document()->owner ?? null;

        return $owner instanceof stdClass
            && count(get_object_vars($owner)) === 2
            && ($owner->id ?? null) === 'kumwe.app/content'
            && ($owner->version ?? null) === '2.0.0';
    }

    /**
     * Resolve a current or immutable historical artifact without disclosing foreign resources.
     *
     * @param   StudioHostSessionSnapshot  $snapshot   Trusted live host session.
     * @param   StudioArtifactReference    $reference  Strict canonical artifact reference.
     *
     * @return  StoredStudioArtifact  Authorized admitted artifact.
     *
     * @since   2.0.0
     */
    private function find(
        StudioHostSessionSnapshot $snapshot,
        StudioArtifactReference $reference,
    ): StoredStudioArtifact {
        $this->requireResourceId($snapshot, $reference->id);
        $artifact = $reference->revision !== null
            ? $this->artifacts->revision(
                $snapshot->session->siteId,
                $reference->id,
                $reference->version,
                $reference->revision,
            )
            : $this->artifacts->current(
                $snapshot->session->siteId,
                $reference->id,
                $reference->version,
            );
        if ($artifact === null) {
            throw new StudioHostOperationRefused('not-found', 'studio.artifact/not-found');
        }
        $this->requireResource($snapshot, $artifact->id, $artifact->kind);

        return $artifact;
    }

    /**
     * Decode the exact published ArtifactReference HTTP wrapper member.
     *
     * @param   StudioHostRequest  $request  Validated canonical request.
     *
     * @return  StudioArtifactReference  Strict canonical reference value.
     *
     * @since   2.0.0
     */
    private function referenceArgument(StudioHostRequest $request): StudioArtifactReference
    {
        $arguments = $this->exactArguments($request, ['reference']);
        $reference = $arguments->reference;
        if (!$reference instanceof stdClass) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $members = array_keys(get_object_vars($reference));
        sort($members, SORT_STRING);
        if (
            !in_array($members, [
            ['id', 'version'],
            ['id', 'integrity', 'version'],
            ['id', 'revision', 'version'],
            ['id', 'integrity', 'revision', 'version'],
            ], true)
        ) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $id = $reference->id;
        $version = $reference->version;
        $revision = property_exists($reference, 'revision') ? $reference->revision : null;
        $integrity = property_exists($reference, 'integrity') ? $reference->integrity : null;
        if (
            !is_string($id) || $id === '' || strlen($id) > 240
            || !is_string($version) || $version === '' || strlen($version) > 100
            || $revision !== null && (!is_string($revision) || $revision === '' || strlen($revision) > 200)
            || $integrity !== null && (!is_string($integrity) || $integrity === '' || strlen($integrity) > 500)
        ) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }

        return new StudioArtifactReference($id, $version, $revision, $integrity);
    }

    /**
     * Require an exact closed HTTP wrapper shape.
     *
     * @param   StudioHostRequest  $request  Validated canonical request.
     * @param   list<string>       $members  Required member names.
     *
     * @return  stdClass  Exact argument wrapper.
     *
     * @since   2.0.0
     */
    private function exactArguments(StudioHostRequest $request, array $members): stdClass
    {
        if (!$request->arguments instanceof stdClass) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $actual = array_keys(get_object_vars($request->arguments));
        sort($actual, SORT_STRING);
        sort($members, SORT_STRING);
        if ($actual !== $members) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }

        return $request->arguments;
    }

    /**
     * Refuse mutation context fields on read-only artifact operations.
     *
     * @param   StudioHostRequest  $request  Validated canonical request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireReadContext(StudioHostRequest $request): void
    {
        if ($request->expectedRevision !== null || $request->idempotencyKey !== null) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-context');
        }
    }

    /**
     * Require the optimistic revision carried by every artifact mutation.
     *
     * @param   StudioHostRequest  $request  Validated canonical request.
     *
     * @return  string  Non-empty expected revision.
     *
     * @since   2.0.0
     */
    private function requireExpectedRevision(StudioHostRequest $request): string
    {
        if ($request->expectedRevision === null || $request->expectedRevision === '') {
            throw new StudioHostOperationRefused('invalid-request', 'studio.artifact/expected-revision-required');
        }

        return $request->expectedRevision;
    }

    /**
     * Require one effective server-resolved Studio permission.
     *
     * @param   StudioHostSessionSnapshot  $snapshot    Trusted live host session.
     * @param   string                     $permission  Canonical permission name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requirePermission(StudioHostSessionSnapshot $snapshot, string $permission): void
    {
        if (!in_array($permission, $snapshot->permissions, true)) {
            throw new StudioHostOperationRefused('forbidden', 'studio.host/action-forbidden');
        }
    }

    /**
     * Require the exact server-resolved authority for one lifecycle transition target.
     *
     * @param   StudioHostSessionSnapshot  $snapshot   Trusted live host session.
     * @param   bool                       $published  Whether the target status is published.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireLifecyclePermission(StudioHostSessionSnapshot $snapshot, bool $published): void
    {
        if ($published ? !$snapshot->canPublish : !$snapshot->canUnpublish) {
            throw new StudioHostOperationRefused('forbidden', 'studio.host/action-forbidden');
        }
    }

    /**
     * Require the artifact identifier bound into the trusted session.
     *
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     * @param   string                     $id        Candidate artifact identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireResourceId(StudioHostSessionSnapshot $snapshot, string $id): void
    {
        if (!hash_equals($snapshot->session->resourceId, $id)) {
            throw new StudioHostOperationRefused('not-found', 'studio.artifact/not-found');
        }
    }

    /**
     * Require the artifact kind to fit the trusted resource family and canonical mode.
     *
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     * @param   string                     $id        Candidate artifact identifier.
     * @param   string                     $kind      Admitted artifact kind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireResource(StudioHostSessionSnapshot $snapshot, string $id, string $kind): void
    {
        $this->requireResourceId($snapshot, $id);
        $fits = match ($kind) {
            'blueprint' => $snapshot->session->resourceKind === StudioResourceKind::Blueprint
                && in_array(
                    $snapshot->session->mode,
                    [StudioSessionMode::Blueprint, StudioSessionMode::ReadOnly],
                    true,
                ),
            'content-model' => $snapshot->session->resourceKind === StudioResourceKind::Content
                && in_array($snapshot->session->mode, [StudioSessionMode::Model, StudioSessionMode::ReadOnly], true),
            'entry' => $snapshot->session->resourceKind === StudioResourceKind::Content
                && in_array(
                    $snapshot->session->mode,
                    [StudioSessionMode::Content, StudioSessionMode::Hybrid, StudioSessionMode::ReadOnly],
                    true,
                ),
            default => false,
        };
        if (!$fits) {
            throw new StudioHostOperationRefused('forbidden', 'studio.host/session-refused');
        }
    }

    /**
     * Reveal a current revision only for the artifact already bound to the session.
     *
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     * @param   string                     $id        Candidate artifact identifier.
     * @param   string                     $version   Candidate artifact version.
     *
     * @return  string|null  Safe current revision or null.
     *
     * @since   2.0.0
     */
    private function safeCurrentRevision(
        StudioHostSessionSnapshot $snapshot,
        string $id,
        string $version,
    ): ?string {
        if (!hash_equals($snapshot->session->resourceId, $id)) {
            return null;
        }

        return $this->artifacts->current($snapshot->session->siteId, $id, $version)?->revision;
    }

    /**
     * Derive one deterministic host revision from the admitted artifact and request.
     *
     * @param   StoredStudioArtifact  $artifact  Admitted mutation input.
     * @param   StudioHostRequest     $request   Validated canonical request.
     * @param   string                $previous  Optimistic predecessor revision.
     *
     * @return  string  Deterministic opaque revision.
     *
     * @since   2.0.0
     */
    private function nextRevision(
        StoredStudioArtifact $artifact,
        StudioHostRequest $request,
        string $previous,
    ): string {
        return 'studio-r/' . hash('sha256', CanonicalJson::stringify((object) [
            'artifactDigest' => hash('sha256', $artifact->canonicalDocument),
            'id' => $artifact->id,
            'operationId' => $request->operationId,
            'previousRevision' => $previous,
            'requestId' => $request->requestId,
            'version' => $artifact->version,
        ]));
    }
}
