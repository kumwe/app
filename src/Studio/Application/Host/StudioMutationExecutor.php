<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Host\StudioIdempotencyRecord;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Atomic at-most-once boundary shared by artifact and recovery mutations.
 *
 * @since  2.0.0
 */
final readonly class StudioMutationExecutor
{
    /**
     * Bind durable idempotency claims to the platform transaction boundary.
     *
     * @param  TransactionManager           $transactions  Authoritative transaction manager.
     * @param  StudioIdempotencyRepository  $idempotency   Durable mutation replay ledger.
     * @param  AuditRecorder                $audit         Transactional disclosure-safe audit sink.
     * @param  ClockInterface               $clock         Trusted audit-event clock.
     *
     * @since  2.0.0
     */
    public function __construct(
        private TransactionManager $transactions,
        private StudioIdempotencyRepository $idempotency,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Execute or replay one mutation under actor/session/resource/operation/key scope.
     *
     * Request and trace correlation are deliberately absent from the intent. Expected revision, locale,
     * protocol version and the canonical semantic argument are included, matching the pinned testkit.
     * A changed intent is refused before optimistic concurrency and a failed operation rolls its claim
     * back with every side effect.
     *
     * @param   StudioHostSessionSnapshot     $snapshot   Trusted live host session.
     * @param   StudioHostRequest             $request    Validated canonical request.
     * @param   mixed                         $argument   Semantic port argument, not its HTTP wrapper.
     * @param   callable(): StudioHostResult  $operation  Atomic mutation body.
     *
     * @return  StudioHostResult  New result or exact completed replay.
     *
     * @since   2.0.0
     */
    public function execute(
        StudioHostSessionSnapshot $snapshot,
        StudioHostRequest $request,
        mixed $argument,
        callable $operation,
    ): StudioHostResult {
        if ($request->idempotencyKey === null) {
            return $this->transactions->transactional(
                fn (): StudioHostResult => $this->perform($snapshot, $request, $operation),
            );
        }
        $scopeDigest = hash('sha256', CanonicalJson::stringify((object) [
            'actorId' => $snapshot->session->actorId,
            'idempotencyKey' => $request->idempotencyKey,
            'operationId' => $request->operationId,
            'resourceContextKey' => $request->resourceContextKey,
            'sessionBinding' => $snapshot->session->sessionBinding,
            'sessionGeneration' => $request->sessionGeneration,
        ]));
        $intent = (object) ['argument' => $argument, 'context' => (object) [
            'protocolVersion' => $request->protocolVersion,
        ]];
        if ($request->expectedRevision !== null) {
            $intent->context->expectedRevision = $request->expectedRevision;
        }
        if ($request->locale !== null) {
            $intent->context->locale = $request->locale;
        }
        $intentDigest = hash('sha256', CanonicalJson::stringify($intent));
        $prior = $this->idempotency->find($scopeDigest);
        if ($prior !== null) {
            return $this->replay($prior, $intentDigest);
        }

        try {
            return $this->transactions->transactional(function () use (
                $scopeDigest,
                $intentDigest,
                $snapshot,
                $request,
                $operation,
            ): StudioHostResult {
                $prior = $this->idempotency->find($scopeDigest);
                if ($prior !== null) {
                    return $this->replay($prior, $intentDigest);
                }
                $this->idempotency->begin(
                    new StudioIdempotencyRecord($scopeDigest, $intentDigest, null),
                    $snapshot,
                    $request,
                );
                $result = $this->perform($snapshot, $request, $operation);
                $bytes = CanonicalJson::stringify($result->document());
                $this->idempotency->complete($scopeDigest, $bytes);

                return $result;
            });
        } catch (StudioIdempotencyRace) {
            $winner = $this->idempotency->find($scopeDigest);
            if ($winner === null) {
                throw new StudioHostOperationRefused(
                    'unavailable',
                    'studio.host/idempotency-in-progress',
                    null,
                    true,
                );
            }

            return $this->replay($winner, $intentDigest);
        } catch (StudioPersistenceRace) {
            throw new StudioHostOperationRefused(
                'unavailable',
                'studio.host/concurrent-mutation',
                null,
                true,
            );
        }
    }

    /**
     * Perform and audit one successful mutation inside its authoritative transaction.
     *
     * Audit metadata carries only trusted coordinates, digests and the resulting revision. It never
     * includes the artifact document, recovery envelope, context key, session binding, or idempotency key.
     * A recorder failure escapes so the mutation and any idempotency claim roll back together.
     *
     * @param   StudioHostSessionSnapshot     $snapshot   Trusted live host session.
     * @param   StudioHostRequest             $request    Validated canonical request.
     * @param   callable(): StudioHostResult  $operation  Atomic mutation body.
     *
     * @return  StudioHostResult  Successfully applied mutation result.
     *
     * @since   2.0.0
     */
    private function perform(
        StudioHostSessionSnapshot $snapshot,
        StudioHostRequest $request,
        callable $operation,
    ): StudioHostResult {
        $result = $operation();
        $session = $snapshot->session;
        $subjectType = str_starts_with($request->operationId, 'studio.operation/artifact.')
            ? 'studio_artifact'
            : 'studio_recovery';
        $resourceDigest = hash('sha256', CanonicalJson::stringify((object) [
            'resourceId' => $session->resourceId,
            'siteId' => $session->siteId,
        ]));
        $metadata = [
            'idempotent' => $request->idempotencyKey !== null,
            'mode' => $session->mode->value,
            'operation_id' => $request->operationId,
            'resource_identity_digest' => $resourceDigest,
            'resource_kind' => $session->resourceKind->value,
            'site_identifier' => $session->siteId,
        ];
        if ($result->revision !== null) {
            $metadata['revision'] = $result->revision;
        }
        if ($request->idempotencyKey !== null) {
            $metadata['idempotency_key_digest'] = hash('sha256', $request->idempotencyKey);
        }
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $session->actorId,
            str_replace('studio.operation/', 'studio.', $request->operationId),
            $subjectType,
            $resourceDigest,
            'success',
            $metadata,
        ));

        return $result;
    }

    /**
     * Prove intent equality and recover the completed result.
     *
     * @param   StudioIdempotencyRecord  $prior         Existing durable replay record.
     * @param   string                   $intentDigest  Digest of the new canonical intent.
     *
     * @return  StudioHostResult  Previously completed canonical result.
     *
     * @since   2.0.0
     */
    private function replay(StudioIdempotencyRecord $prior, string $intentDigest): StudioHostResult
    {
        if (!hash_equals($prior->intentDigest, $intentDigest)) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/idempotency-intent-changed');
        }
        if ($prior->resultBytes === null) {
            throw new StudioHostOperationRefused(
                'unavailable',
                'studio.host/idempotency-in-progress',
                null,
                true,
            );
        }
        try {
            return StudioHostResult::fromCanonicalBytes($prior->resultBytes);
        } catch (RuntimeException) {
            throw new StudioHostOperationRefused('internal', 'studio.host/idempotency-corrupt');
        }
    }
}
