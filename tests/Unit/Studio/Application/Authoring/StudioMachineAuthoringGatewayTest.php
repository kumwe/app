<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Authoring;

use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringGateway;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringOperation;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringResult;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringSession;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Wire\OperationRegistry;
use Kumwe\Producer\Wire\RequestEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * Pins the machine authoring vocabulary and every refusal the gateway makes before touching a store.
 *
 * The operation enumeration is read from the pinned Producer registry; refusals carry Producer's canonical
 * category, diagnostics and revision and a closed stable code; and malformed machine input — a browser
 * surface, a key on a read, no key on a mutation, a malformed key, session, generation, locale or target —
 * is refused before any Content, session or host collaborator is consulted.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMachineAuthoringGateway::class)]
#[CoversClass(StudioMachineAuthoringOperation::class)]
#[CoversClass(StudioMachineAuthoringRefused::class)]
#[CoversClass(StudioMachineAuthoringResult::class)]
#[CoversClass(StudioMachineAuthoringSession::class)]
final class StudioMachineAuthoringGatewayTest extends TestCase
{
    /**
     * The seven operations mirror the registry's authoring port exactly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOperationsMirrorThePinnedRegistry(): void
    {
        self::assertSame(
            [
                'resolve-target',
                'list-types',
                'start',
                'plan-save',
                'save-item',
                'save-as-new-type',
                'save-new-type-version',
            ],
            StudioMachineAuthoringOperation::names(),
        );
        $mutating = [];
        foreach (StudioMachineAuthoringOperation::cases() as $operation) {
            $row = OperationRegistry::byCapability($operation->capability());
            self::assertSame('authoring', $row->port);
            self::assertSame($row->route, $operation->route());
            if ($operation->mutating()) {
                $mutating[] = $operation->value;
            }
        }
        self::assertSame(['start', 'save-item', 'save-as-new-type', 'save-new-type-version'], $mutating);
        self::assertSame('query', StudioMachineAuthoringOperation::ListTypes->argumentMember());
        self::assertSame('intent', StudioMachineAuthoringOperation::PlanSave->argumentMember());
        self::assertSame('request', StudioMachineAuthoringOperation::SaveItem->argumentMember());
        self::assertSame(StudioMachineAuthoringOperation::Start, StudioMachineAuthoringOperation::named('start'));
        $refused = self::refusal(static fn () => StudioMachineAuthoringOperation::named('publish'));
        self::assertSame(['studio.machine/operation-unknown'], $refused->diagnosticCodes());
    }

    /**
     * Refusals expose Producer's category, diagnostics, revision, status and closed stable code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsCarryTheCanonicalHostError(): void
    {
        $conflict = StudioMachineAuthoringRefused::of('conflict', 'studio.authoring/expected-mismatch', 'entry-r3');
        self::assertSame('conflict', $conflict->category());
        self::assertSame(['studio.authoring/expected-mismatch'], $conflict->diagnosticCodes());
        self::assertSame('entry-r3', $conflict->revision());
        self::assertSame(409, $conflict->status());
        self::assertFalse($conflict->retryable());
        self::assertSame('studio_authoring.conflict', $conflict->stableCode());
        self::assertSame('host-error', $conflict->document()->kind);
        self::assertSame('entry-r3', $conflict->document()->revision);

        $reused = StudioMachineAuthoringRefused::of('invalid-request', 'studio.host/idempotency-intent-changed');
        self::assertTrue($reused->keyReused());
        self::assertSame('studio_authoring.idempotency_key_reused', $reused->stableCode());
        $producerReused = StudioMachineAuthoringRefused::of(
            'invalid-request',
            'kumwe.producer/idempotent-intent-changed',
        );
        self::assertTrue($producerReused->keyReused());

        $running = StudioMachineAuthoringRefused::of('unavailable', 'studio.host/idempotency-in-progress', null, true);
        self::assertTrue($running->inProgress());
        self::assertTrue($running->retryable());
        self::assertSame(503, $running->status());
        self::assertSame('studio_authoring.idempotency_in_progress', $running->stableCode());
        self::assertSame(
            'studio_authoring.validation_failed',
            StudioMachineAuthoringRefused::of('validation-failed', 'studio.authoring/unknown-target')->stableCode(),
        );
    }

    /**
     * Session and result documents carry exactly the coordinates a caller echoes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSessionAndResultDocuments(): void
    {
        $blank = new StudioMachineAuthoringSession(
            'contexts/key',
            'sessions/one',
            'session-one',
            StudioAuthoringIntent::Create,
            (object) ['key' => 'contexts/key'],
            ['studio.permission/read'],
            ['blank', 'from-type'],
            null,
            '/administrator/content/new',
        );
        $document = $blank->toDocument();
        self::assertSame(StudioMachineAuthoringSession::KIND, $document->kind);
        self::assertSame('contexts/key', $document->session);
        self::assertSame(RequestEnvelope::WIRE_PROTOCOL_VERSION, $document->protocolVersion);
        self::assertSame(StudioMachineAuthoringOperation::names(), $document->operations);
        self::assertObjectNotHasProperty('type', $document);

        $typed = new StudioMachineAuthoringSession(
            'contexts/key',
            'sessions/one',
            'session-one',
            StudioAuthoringIntent::Create,
            new stdClass(),
            [],
            ['blank', 'from-type'],
            (object) ['id' => 'content-type:one'],
            '/administrator/content/new',
        );
        self::assertSame('content-type:one', $typed->toDocument()->type->id);

        $result = new StudioMachineAuthoringResult(
            StudioMachineAuthoringOperation::Start,
            (object) ['value' => (object) ['kind' => 'authoring-session']],
            true,
        );
        self::assertSame('authoring-session', $result->value()->kind);
        self::assertEquals(
            new stdClass(),
            (new StudioMachineAuthoringResult(StudioMachineAuthoringOperation::Start, new stdClass(), false))->value(),
        );
    }

    /**
     * Malformed machine input is refused before any collaborator is consulted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedMachineInputIsRefusedBeforeAnyStore(): void
    {
        $gateway = self::gateway();
        $machine = self::context(AuthenticatedSurface::Api);
        $argument = new stdClass();
        $cases = [
            'studio.machine/surface-refused' => static fn () => $gateway->open(
                self::context(AuthenticatedSurface::Administrator),
                StudioAuthoringIntent::Create,
            ),
            'studio.machine/idempotency-key-required' => static fn () => $gateway->perform(
                $machine,
                StudioMachineAuthoringOperation::SaveItem,
                'contexts/key',
                'session-one',
                $argument,
                null,
            ),
            'studio.machine/idempotency-key-unexpected' => static fn () => $gateway->perform(
                $machine,
                StudioMachineAuthoringOperation::PlanSave,
                'contexts/key',
                'session-one',
                $argument,
                'replay-key-0001',
            ),
            'studio.machine/idempotency-key-invalid' => static fn () => $gateway->mutate(
                $machine,
                'contexts/key',
                'session-one',
                StudioMachineAuthoringOperation::Start,
                $argument,
                'not a key',
            ),
            'studio.machine/session-invalid' => static fn () => $gateway->read(
                $machine,
                'not a session',
                'session-one',
                StudioMachineAuthoringOperation::ResolveTarget,
                $argument,
            ),
            'studio.machine/generation-invalid' => static fn () => $gateway->read(
                $machine,
                'contexts/key',
                '',
                StudioMachineAuthoringOperation::ResolveTarget,
                $argument,
            ),
            'studio.machine/locale-invalid' => static fn () => $gateway->read(
                $machine,
                'contexts/key',
                'session-one',
                StudioMachineAuthoringOperation::ResolveTarget,
                $argument,
                'not a locale!',
            ),
        ];
        foreach ($cases as $diagnostic => $call) {
            $refused = self::refusal($call);
            self::assertSame([$diagnostic], $refused->diagnosticCodes(), $diagnostic);
        }

        foreach (
            [
                'edit without an entry' => [StudioAuthoringIntent::Edit, null, null, null],
                'edit with a type' => [StudioAuthoringIntent::Edit, 'entry-1', 'type-1', null],
                'create with an entry' => [StudioAuthoringIntent::Create, 'entry-1', null, null],
                'version without a type' => [StudioAuthoringIntent::Create, null, null, 2],
                'zero version' => [StudioAuthoringIntent::Create, null, 'type-1', 0],
                'control byte' => [StudioAuthoringIntent::Create, null, "type\x01", null],
                'empty entry' => [StudioAuthoringIntent::Edit, '', null, null],
            ] as $label => [$intent, $content, $type, $version]
        ) {
            $refused = self::refusal(static fn () => $gateway->open($machine, $intent, $content, $type, $version));
            self::assertSame(['studio.machine/target-invalid'], $refused->diagnosticCodes(), $label);
        }
    }

    /**
     * An argument that cannot be written as a wire envelope is refused before any Studio host is created.
     *
     * Machine arguments arrive already decoded, so bytes that are not UTF-8 can reach the gateway from a
     * transport that does not validate them. The envelope is canonical JSON, and a value it cannot carry is the
     * caller's malformed input, not an internal failure of the host that would have received it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnArgumentTheEnvelopeCannotCarryIsRefusedBeforeAnyHostIsCreated(): void
    {
        $gateway = self::gateway();
        $argument = (object) ['title' => "\xB1\x31 invalid"];

        foreach (
            [
                static fn () => $gateway->read(
                    self::context(AuthenticatedSurface::Api),
                    'contexts/key',
                    'session-one',
                    StudioMachineAuthoringOperation::ResolveTarget,
                    $argument,
                ),
                static fn () => $gateway->mutate(
                    self::context(AuthenticatedSurface::Mcp),
                    'contexts/key',
                    'session-one',
                    StudioMachineAuthoringOperation::SaveItem,
                    $argument,
                    'replay-key-0001',
                ),
            ] as $call
        ) {
            $refused = self::refusal($call);
            self::assertSame('invalid-request', $refused->category());
            self::assertSame(['studio.machine/argument-invalid'], $refused->diagnosticCodes());
        }
    }

    /**
     * Build a gateway whose collaborators exist but must never be reached by these refusals.
     *
     * @return  StudioMachineAuthoringGateway  Gateway over uninitialized collaborators.
     *
     * @since   2.0.0
     */
    private static function gateway(): StudioMachineAuthoringGateway
    {
        return new StudioMachineAuthoringGateway(
            (new ReflectionClass(ContentStudioAuthoringContextAuthority::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(StudioHostSessionAuthority::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ContentStudioAuthoringTargetResolver::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ContentService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ContentModelService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(StudioProducerHostFactory::class))->newInstanceWithoutConstructor(),
        );
    }

    /**
     * A context on one surface.
     *
     * @param   AuthenticatedSurface  $surface  Surface the context authenticated through.
     *
     * @return  ExecutionContext  Test context.
     *
     * @since   2.0.0
     */
    private static function context(AuthenticatedSurface $surface): ExecutionContext
    {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['content.read'],
            'api-token:gateway',
        )->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'gateway-request',
            surface: $surface,
            sessionId: $surface === AuthenticatedSurface::Administrator ? 'browser-session' : null,
        );
    }

    /**
     * Run a call that must be refused and return the refusal.
     *
     * @param   callable(): mixed  $call  Call expected to refuse.
     *
     * @return  StudioMachineAuthoringRefused  The refusal.
     *
     * @since   2.0.0
     */
    private static function refusal(callable $call): StudioMachineAuthoringRefused
    {
        try {
            $call();
        } catch (StudioMachineAuthoringRefused $refused) {
            return $refused;
        }
        self::fail('The call was expected to be refused.');
    }
}
