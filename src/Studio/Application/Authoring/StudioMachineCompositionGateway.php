<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use JsonException;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioSessionSurfaceBinding;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Producer\Error\ContractGrammar;
use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Wire\Dispatcher;
use Kumwe\Producer\Wire\RequestEnvelope;
use stdClass;

/**
 * Gives a machine actor the Blueprint composition editing the administrator composition screen has, through the
 * same Studio host.
 *
 * The composition screen finds the Content type version's bound Blueprint, opens a Blueprint host session for its
 * artifact, and the Studio shell then dispatches canonical envelopes at the `artifact` port: load, dependencies,
 * save, publish and unpublish. This gateway does exactly that for a REST, CLI or MCP caller: `open()` finds the
 * composition through `StudioContentCompositionService` and opens the session bound to the caller's credential;
 * `perform()` builds the Producer envelope server-side — operation identity, protocol version and request
 * identity are never accepted from the caller, which echoes the opaque key and generation `open()` returned and
 * supplies the argument, expected revision and replay key the browser supplies — and hands it to the same
 * request-scoped Producer host the browser route uses. Authorization, the separate publish and unpublish
 * decisions, the pinned schemas, the Blueprint lock and draft continuity checks, keyed replay and optimistic
 * concurrency are therefore one implementation, and every refusal leaves as the canonical `host-error` the
 * browser would have received.
 *
 * @since  2.0.0
 */
final readonly class StudioMachineCompositionGateway
{
    /**
     * Compose the composition finder, the session authority and the host every operation dispatches through.
     *
     * @param  StudioContentCompositionService  $compositions  Finds the exact bound Blueprint of a type version.
     * @param  StudioHostSessionAuthority       $sessions      Canonical host session authority.
     * @param  StudioProducerHostFactory        $hosts         Request-scoped Producer host factory.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioContentCompositionService $compositions,
        private StudioHostSessionAuthority $sessions,
        private StudioProducerHostFactory $hosts,
    ) {
    }

    /**
     * Open a Blueprint composition session for one exact, already provisioned Content type version.
     *
     * @param   ExecutionContext  $context             Authenticated machine execution context.
     * @param   string            $contentTypeId       Content type UUID.
     * @param   int               $contentTypeVersion  Exact Content type version.
     * @param   bool              $readOnly            Whether to open a session that may only read.
     *
     * @return  StudioMachineCompositionSession  Opened session the caller addresses later operations by.
     *
     * @throws  StudioMachineAuthoringRefused  When the surface, composition, theme lock or session policy refuses.
     *
     * @since   2.0.0
     */
    public function open(
        ExecutionContext $context,
        string $contentTypeId,
        int $contentTypeVersion,
        bool $readOnly = false,
    ): StudioMachineCompositionSession {
        self::assertMachine($context);
        if ($contentTypeVersion < 1) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid');
        }
        try {
            $composition = $this->compositions->find($context, $contentTypeId, $contentTypeVersion);
        } catch (StudioCompositionThemeMismatch) {
            throw StudioMachineAuthoringRefused::of('conflict', 'studio.composition/theme-mismatch');
        } catch (StudioProjectionRejected) {
            $composition = null;
        }
        if ($composition === null) {
            throw StudioMachineAuthoringRefused::of('not-found', 'studio.composition/not-found');
        }
        try {
            $snapshot = $this->sessions->open(
                $context,
                $readOnly ? StudioSessionMode::ReadOnly : StudioSessionMode::Blueprint,
                StudioResourceKind::Blueprint,
                $composition->binding->blueprintId,
            );
        } catch (StudioHostAccessRefused $refused) {
            throw StudioMachineAuthoringRefused::of($refused->category, $refused->diagnosticCode);
        }

        return new StudioMachineCompositionSession(
            $snapshot->session->resourceContextKey,
            $snapshot->generation,
            $snapshot->session->mode->value,
            $snapshot->permissions,
            $snapshot->canPublish,
            $snapshot->canUnpublish,
            $composition->binding->contentTypeId,
            $composition->binding->contentTypeVersion,
            (object) [
                'id' => $composition->binding->blueprintId,
                'version' => $composition->binding->blueprintVersion,
                'revision' => $composition->blueprint->revision,
            ],
        );
    }

    /**
     * Perform one artifact operation against an opened composition session.
     *
     * A mutation needs the caller's replay key and the expected revision it read, exactly as the browser sends
     * them; a read carries neither. The key enters Producer's envelope, so replay, changed intent and
     * in-progress refusals are the host mutation boundary's decisions — the same ones the browser gets.
     *
     * @param   ExecutionContext                   $context           Authenticated machine execution context.
     * @param   StudioMachineCompositionOperation  $operation         Operation to dispatch.
     * @param   string                             $sessionKey        Opaque session key `open()` returned.
     * @param   string                             $generation        Session generation `open()` returned.
     * @param   stdClass                           $argument          The artifact reference, or the document to save.
     * @param   ?string                            $expectedRevision  Revision a mutation expects to replace.
     * @param   ?string                            $idempotencyKey    Replay key, required exactly for mutations.
     * @param   ?string                            $locale            Caller locale tag, or null.
     *
     * @return  StudioMachineCompositionResult  Canonical Producer result, fresh or replayed.
     *
     * @throws  StudioMachineAuthoringRefused  When the key or revision does not fit the operation, or the host
     *          refuses.
     *
     * @since   2.0.0
     */
    public function perform(
        ExecutionContext $context,
        StudioMachineCompositionOperation $operation,
        string $sessionKey,
        string $generation,
        stdClass $argument,
        ?string $expectedRevision,
        ?string $idempotencyKey,
        ?string $locale = null,
    ): StudioMachineCompositionResult {
        self::assertMachine($context);
        if ($operation->mutating() && $idempotencyKey === null) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/idempotency-key-required');
        }
        if (!$operation->mutating() && $idempotencyKey !== null) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/idempotency-key-unexpected');
        }
        if ($idempotencyKey !== null && !ContractGrammar::isStableId($idempotencyKey)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/idempotency-key-invalid');
        }
        if ($expectedRevision !== null && !ContractGrammar::isRevision($expectedRevision)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/revision-invalid');
        }
        if (!ContractGrammar::isStableId($sessionKey)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/session-invalid');
        }
        if (!ContractGrammar::isRevision($generation)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/generation-invalid');
        }
        if ($locale !== null && !ContractGrammar::isLocale($locale)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/locale-invalid');
        }

        $envelopeContext = (object) [
            'operationId' => $operation->capability(),
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'requestId' => 'requests/' . substr(hash('sha256', $context->requestId()), 0, 40),
            'resourceContextKey' => $sessionKey,
            'sessionGeneration' => $generation,
        ];
        if ($expectedRevision !== null) {
            $envelopeContext->expectedRevision = $expectedRevision;
        }
        if ($idempotencyKey !== null) {
            $envelopeContext->idempotencyKey = $idempotencyKey;
        }
        if ($locale !== null) {
            $envelopeContext->locale = $locale;
        }
        try {
            $body = json_encode(
                (object) [
                    'arguments' => (object) [$operation->argumentMember() => $argument],
                    'context' => $envelopeContext,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/argument-invalid');
        }

        $host = $this->hosts->create($context);
        $response = (new Dispatcher($host))->dispatch($operation->route(), $body);
        if ($response->refusalCategory !== null) {
            throw new StudioMachineAuthoringRefused(HostError::fromCanonicalBytes($response->body));
        }
        $document = json_decode($response->body, false, 64, JSON_THROW_ON_ERROR);
        if (!$document instanceof stdClass) {
            throw StudioMachineAuthoringRefused::of('internal', 'studio.machine/result-invalid');
        }
        $authority = $host->authorization();

        return new StudioMachineCompositionResult(
            $operation,
            $document,
            $authority instanceof StudioProducerRequestAuthority && $authority->replayed(),
        );
    }

    /**
     * Refuse every surface that is not one of the credentialed machine surfaces.
     *
     * @param   ExecutionContext  $context  Execution context presenting the request.
     *
     * @return  void
     *
     * @throws  StudioMachineAuthoringRefused  When the context authenticated through a browser or system surface.
     *
     * @since   2.0.0
     */
    private static function assertMachine(ExecutionContext $context): void
    {
        if (!StudioSessionSurfaceBinding::isMachine($context->surface())) {
            throw StudioMachineAuthoringRefused::of('forbidden', 'studio.machine/surface-refused');
        }
    }
}
