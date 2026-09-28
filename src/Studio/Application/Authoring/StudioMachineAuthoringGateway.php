<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use JsonException;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioSessionSurfaceBinding;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\Content\Application\ContentModelNotFound;
use Kumwe\Content\Application\ContentNotFound;
use Kumwe\Content\Domain\ContentTypeDefinition;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Producer\Error\ContractGrammar;
use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Wire\Dispatcher;
use Kumwe\Producer\Wire\RequestEnvelope;
use stdClass;

/**
 * Gives a machine actor the same Studio authoring journey the administrator browser has, through the same host.
 *
 * The browser mount opens an opaque authoring context and a hybrid host session, and the Studio shell then
 * dispatches canonical envelopes at the seven authoring operations. This gateway does exactly that for a
 * REST, CLI or MCP caller: `open()` resolves the target through the authorized Content services and binds
 * the context and session to the caller's credential; the operations then build the Producer envelope
 * server-side — operation identity, protocol version and request identity are never accepted from the
 * caller, which only echoes the opaque key and generation `open()` returned — and hand it to the same
 * request-scoped Producer host the browser route uses, so
 * authorization, the pinned schemas, keyed replay, the transaction boundary and the audit event are one
 * implementation. Every refusal leaves as the canonical `host-error` the browser would have received.
 *
 * @since  2.0.0
 */
final readonly class StudioMachineAuthoringGateway
{
    /**
     * Longest identifier a caller may name a Content entry or type by.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int IDENTIFIER_BYTES = 191;

    /**
     * Compose the authorities the browser mount composes, plus the host every operation dispatches through.
     *
     * @param  ContentStudioAuthoringContextAuthority  $contexts  Opaque exact-target authoring contexts.
     * @param  StudioHostSessionAuthority              $sessions  Canonical host session authority.
     * @param  ContentStudioAuthoringTargetResolver    $targets   Exact create/update authorization boundary.
     * @param  ContentService                          $content   Authorized exact Content-entry reader.
     * @param  ContentModelService                     $models    Authorized exact Content-type reader.
     * @param  StudioProducerHostFactory               $hosts     Request-scoped Producer host factory.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentStudioAuthoringContextAuthority $contexts,
        private StudioHostSessionAuthority $sessions,
        private ContentStudioAuthoringTargetResolver $targets,
        private ContentService $content,
        private ContentModelService $models,
        private StudioProducerHostFactory $hosts,
    ) {
    }

    /**
     * Open an authoring context and host session for one exact create or edit target.
     *
     * @param   ExecutionContext       $context             Authenticated machine execution context.
     * @param   StudioAuthoringIntent  $intent              Whether the caller creates a new item or edits one.
     * @param   ?string                $contentId           Entry to edit; required for edit, refused for create.
     * @param   ?string                $contentTypeId       Reusable type a create starts from, or null for blank.
     * @param   ?int                   $contentTypeVersion  Exact type version, or null for its current version.
     *
     * @return  StudioMachineAuthoringSession  Opened session the caller addresses later operations by.
     *
     * @throws  StudioMachineAuthoringRefused  When the surface, target, live authority or session policy refuses.
     *
     * @since   2.0.0
     */
    public function open(
        ExecutionContext $context,
        StudioAuthoringIntent $intent,
        ?string $contentId = null,
        ?string $contentTypeId = null,
        ?int $contentTypeVersion = null,
    ): StudioMachineAuthoringSession {
        self::assertMachine($context);
        $target = $this->target($context, $intent, $contentId, $contentTypeId, $contentTypeVersion);
        try {
            $contextKey = $this->contexts->open($context, $target);
            $snapshot = $this->sessions->open(
                $context,
                StudioSessionMode::Hybrid,
                StudioResourceKind::ContentAuthoring,
                $contextKey,
            );
        } catch (ContentStudioAuthoringContextRefused) {
            throw StudioMachineAuthoringRefused::of('forbidden', 'studio.authoring/context-refused');
        } catch (ContentStudioAuthoringContextStale $stale) {
            throw StudioMachineAuthoringRefused::of(
                'conflict',
                'studio.authoring/context-stale',
                $stale->current->entryRevision,
            );
        } catch (StudioHostAccessRefused $refused) {
            throw StudioMachineAuthoringRefused::of($refused->category, $refused->diagnosticCode);
        }
        $session = new ContentStudioAuthoringSession(
            $snapshot->session,
            $target,
            $snapshot->generation,
            $snapshot->permissions,
        );

        return new StudioMachineAuthoringSession(
            $session->key(),
            $session->sessionId(),
            $session->generation,
            $target->intent,
            $session->resourceContext(),
            $session->permissions,
            $target->intent === StudioAuthoringIntent::Edit ? ['existing'] : ['blank', 'from-type'],
            self::typeReference($target),
            $target->returnPath,
        );
    }

    /**
     * Perform one authoring operation, choosing the read or keyed-mutation path from the caller's key.
     *
     * Every adapter calls this one entry so a missing key on a mutation, or a key on a read, is refused
     * with the same diagnostic on every surface.
     *
     * @param   ExecutionContext                 $context         Authenticated machine execution context.
     * @param   StudioMachineAuthoringOperation  $operation       Operation to dispatch.
     * @param   string                           $sessionKey      Opaque session key `open()` returned.
     * @param   string                           $generation      Session generation `open()` returned.
     * @param   stdClass                         $argument        The operation's single argument document.
     * @param   ?string                          $idempotencyKey  Replay key, required exactly for mutations.
     * @param   ?string                          $locale          Caller locale tag, or null.
     *
     * @return  StudioMachineAuthoringResult  Canonical Producer result.
     *
     * @throws  StudioMachineAuthoringRefused  When the key does not fit the operation, or the host refuses.
     *
     * @since   2.0.0
     */
    public function perform(
        ExecutionContext $context,
        StudioMachineAuthoringOperation $operation,
        string $sessionKey,
        string $generation,
        stdClass $argument,
        ?string $idempotencyKey,
        ?string $locale = null,
    ): StudioMachineAuthoringResult {
        return $idempotencyKey === null
            ? $this->read($context, $sessionKey, $generation, $operation, $argument, $locale)
            : $this->mutate($context, $sessionKey, $generation, $operation, $argument, $idempotencyKey, $locale);
    }

    /**
     * Perform one non-mutating authoring operation against an opened session.
     *
     * @param   ExecutionContext                 $context     Authenticated machine execution context.
     * @param   string                           $sessionKey  Opaque session key `open()` returned.
     * @param   string                           $generation  Session generation `open()` returned; a stale one
     *          is refused by the same fence the browser meets.
     * @param   StudioMachineAuthoringOperation  $operation   Read operation to dispatch.
     * @param   stdClass                         $argument    The operation's single argument document.
     * @param   ?string                          $locale      Caller locale tag, or null.
     *
     * @return  StudioMachineAuthoringResult  Canonical Producer result.
     *
     * @throws  StudioMachineAuthoringRefused  When the operation is a mutation, or the host refuses.
     *
     * @since   2.0.0
     */
    public function read(
        ExecutionContext $context,
        string $sessionKey,
        string $generation,
        StudioMachineAuthoringOperation $operation,
        stdClass $argument,
        ?string $locale = null,
    ): StudioMachineAuthoringResult {
        if ($operation->mutating()) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/idempotency-key-required');
        }

        return $this->dispatch($context, $sessionKey, $generation, $operation, $argument, null, $locale);
    }

    /**
     * Perform one mutating authoring operation under a caller-chosen idempotency key.
     *
     * The key enters Producer's envelope, so replay, changed intent and in-progress refusals are the
     * host mutation boundary's decisions — the same ones the browser gets — and never a second ledger.
     *
     * @param   ExecutionContext                 $context         Authenticated machine execution context.
     * @param   string                           $sessionKey      Opaque session key `open()` returned.
     * @param   string                           $generation      Session generation `open()` returned.
     * @param   StudioMachineAuthoringOperation  $operation       Mutating operation to dispatch.
     * @param   stdClass                         $argument        The operation's single argument document.
     * @param   string                           $idempotencyKey  Stable caller-chosen replay key.
     * @param   ?string                          $locale          Caller locale tag, or null.
     *
     * @return  StudioMachineAuthoringResult  Fresh committed result or the exact stored replay.
     *
     * @throws  StudioMachineAuthoringRefused  When the operation is a read, the key is malformed, or the host
     *          refuses.
     *
     * @since   2.0.0
     */
    public function mutate(
        ExecutionContext $context,
        string $sessionKey,
        string $generation,
        StudioMachineAuthoringOperation $operation,
        stdClass $argument,
        string $idempotencyKey,
        ?string $locale = null,
    ): StudioMachineAuthoringResult {
        if (!$operation->mutating()) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/idempotency-key-unexpected');
        }
        if (!ContractGrammar::isStableId($idempotencyKey)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/idempotency-key-invalid');
        }

        return $this->dispatch($context, $sessionKey, $generation, $operation, $argument, $idempotencyKey, $locale);
    }

    /**
     * Build the canonical envelope server-side and run it through the request-scoped Producer host.
     *
     * @param   ExecutionContext                 $context         Authenticated machine execution context.
     * @param   string                           $sessionKey      Opaque session key to resolve.
     * @param   string                           $generation      Session generation the caller echoes.
     * @param   StudioMachineAuthoringOperation  $operation       Operation to dispatch.
     * @param   stdClass                         $argument        The operation's single argument document.
     * @param   ?string                          $idempotencyKey  Replay key for a mutation, or null for a read.
     * @param   ?string                          $locale          Caller locale tag, or null.
     *
     * @return  StudioMachineAuthoringResult  Canonical Producer result.
     *
     * @throws  StudioMachineAuthoringRefused  When the surface, key, locale, session or host refuses.
     *
     * @since   2.0.0
     */
    private function dispatch(
        ExecutionContext $context,
        string $sessionKey,
        string $generation,
        StudioMachineAuthoringOperation $operation,
        stdClass $argument,
        ?string $idempotencyKey,
        ?string $locale,
    ): StudioMachineAuthoringResult {
        self::assertMachine($context);
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
        if ($idempotencyKey !== null) {
            $envelopeContext->idempotencyKey = $idempotencyKey;
        }
        if ($locale !== null) {
            $envelopeContext->locale = $locale;
        }
        $envelope = (object) [
            'arguments' => (object) [$operation->argumentMember() => $argument],
            'context' => $envelopeContext,
        ];
        try {
            $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

        return new StudioMachineAuthoringResult(
            $operation,
            $document,
            $authority instanceof StudioProducerRequestAuthority && $authority->replayed(),
        );
    }

    /**
     * Resolve the trusted target exactly as the Content editor would for the same intent.
     *
     * @param   ExecutionContext       $context             Authenticated machine execution context.
     * @param   StudioAuthoringIntent  $intent              Create or edit.
     * @param   ?string                $contentId           Entry to edit, when editing.
     * @param   ?string                $contentTypeId       Reusable type to start from, when creating.
     * @param   ?int                   $contentTypeVersion  Exact type version, or null for the current one.
     *
     * @return  ContentStudioAuthoringTarget  PHP-resolved, authorized target.
     *
     * @throws  StudioMachineAuthoringRefused  When the selector is malformed, the records are absent, or
     *          create/update authority is refused.
     *
     * @since   2.0.0
     */
    private function target(
        ExecutionContext $context,
        StudioAuthoringIntent $intent,
        ?string $contentId,
        ?string $contentTypeId,
        ?int $contentTypeVersion,
    ): ContentStudioAuthoringTarget {
        if ($contentId !== null && !self::identifier($contentId)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid');
        }
        if ($contentTypeId !== null && !self::identifier($contentTypeId)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid');
        }
        if ($contentTypeVersion !== null && ($contentTypeVersion < 1 || $contentTypeId === null)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid');
        }
        try {
            if ($intent === StudioAuthoringIntent::Edit) {
                if ($contentId === null || $contentTypeId !== null) {
                    throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid');
                }
                $record = $this->content->get($context, $contentId);
                $definition = $this->models->contentType(
                    $context,
                    $record->contentTypeId,
                    $record->contentTypeVersion,
                );

                return $this->targets->edit($context, $record, $definition);
            }
            if ($contentId !== null) {
                throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid');
            }
            $definition = $contentTypeId === null
                ? null
                : $this->models->contentType($context, $contentTypeId, $contentTypeVersion);

            return $this->targets->create($context, $definition);
        } catch (ContentNotFound) {
            throw StudioMachineAuthoringRefused::of('not-found', 'studio.authoring/item-not-found');
        } catch (ContentModelNotFound) {
            throw StudioMachineAuthoringRefused::of('not-found', 'studio.authoring/type-not-found');
        } catch (AuthorizationDenied) {
            throw StudioMachineAuthoringRefused::of('forbidden', 'studio.authoring/context-refused');
        } catch (ContentStudioAuthoringTargetMismatch) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.authoring/target-mismatch');
        }
    }

    /**
     * The reusable-type reference a typed target starts from, in the Studio `startSource` spelling.
     *
     * @param   ContentStudioAuthoringTarget  $target  PHP-resolved target.
     *
     * @return  ?stdClass  `{id, version, revision}` reference, or null for a blank create.
     *
     * @since   2.0.0
     */
    private static function typeReference(ContentStudioAuthoringTarget $target): ?stdClass
    {
        $typeId = $target->modelId === null ? null : ContentStudioProjector::contentTypeId($target->modelId);
        if ($typeId === null || $target->modelVersion === null || $target->modelRevision === null) {
            return null;
        }

        return (object) [
            'id' => ContentStudioAuthoringDocuments::typeId($typeId),
            'version' => $target->modelVersion,
            'revision' => $target->modelRevision,
        ];
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

    /**
     * Bound a caller-supplied Content identifier before any store is consulted.
     *
     * @param   string  $value  Candidate entry or type identifier.
     *
     * @return  bool  True for a non-empty identifier without control bytes inside the stored bound.
     *
     * @since   2.0.0
     */
    private static function identifier(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= self::IDENTIFIER_BYTES
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
