<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Authoring;

use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionGateway;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionOperation;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionResult;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionSession;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\BuildsStudioCompositionService;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
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
 * Pins the machine Blueprint vocabulary and every refusal the gateway makes before a session or host is reached.
 *
 * The five operations are read from the pinned Producer `artifact` port; the session and result documents carry
 * exactly what a caller echoes; and a browser surface, a missing or unexpected key, a malformed key, revision,
 * session, generation or locale, an unprovisioned or unreadable composition and a stale theme lock are refused
 * before any Studio session is opened or any envelope is dispatched.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMachineCompositionGateway::class)]
#[CoversClass(StudioMachineCompositionOperation::class)]
#[CoversClass(StudioMachineCompositionResult::class)]
#[CoversClass(StudioMachineCompositionSession::class)]
final class StudioMachineCompositionGatewayTest extends TestCase
{
    use BuildsStudioCompositionService;

    /**
     * The five operations mirror the registry's artifact port exactly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOperationsMirrorThePinnedArtifactPort(): void
    {
        self::assertSame(
            ['load', 'dependencies', 'save', 'publish', 'unpublish'],
            StudioMachineCompositionOperation::names(),
        );
        $mutating = [];
        foreach (StudioMachineCompositionOperation::cases() as $operation) {
            $row = OperationRegistry::byCapability($operation->capability());
            self::assertSame('artifact', $row->port);
            self::assertSame($row->route, $operation->route());
            if ($operation->mutating()) {
                $mutating[] = $operation->value;
            }
        }
        self::assertSame(['save', 'publish', 'unpublish'], $mutating);
        self::assertSame('document', StudioMachineCompositionOperation::Save->argumentMember());
        self::assertSame('reference', StudioMachineCompositionOperation::Publish->argumentMember());
        self::assertSame(StudioMachineCompositionOperation::Load, StudioMachineCompositionOperation::named('load'));
        $refused = self::refusal(static fn () => StudioMachineCompositionOperation::named('retire'));
        self::assertSame(['studio.machine/operation-unknown'], $refused->diagnosticCodes());
    }

    /**
     * Session and result documents carry exactly the coordinates and values a caller echoes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSessionAndResultDocuments(): void
    {
        $session = (new StudioMachineCompositionSession(
            'contexts/key',
            'session-one',
            'blueprint',
            ['studio.permission/compose', 'studio.permission/read'],
            true,
            false,
            'type-1',
            3,
            (object) ['id' => 'content-blueprint:type-1:v3', 'version' => '1.0.0', 'revision' => 'initial-a'],
        ))->toDocument();

        self::assertSame(StudioMachineCompositionSession::KIND, $session->kind);
        self::assertSame('contexts/key', $session->session);
        self::assertSame('session-one', $session->sessionGeneration);
        self::assertSame(RequestEnvelope::WIRE_PROTOCOL_VERSION, $session->protocolVersion);
        self::assertSame(['type-1', 3], [$session->contentTypeId, $session->contentTypeVersion]);
        self::assertSame('initial-a', $session->artifact->revision);
        self::assertEquals((object) ['canPublish' => true, 'canUnpublish' => false], $session->lifecycle);
        self::assertSame(StudioMachineCompositionOperation::names(), $session->operations);

        $loaded = (new StudioMachineCompositionResult(
            StudioMachineCompositionOperation::Load,
            (object) ['value' => (object) ['kind' => 'blueprint'], 'revision' => 'initial-a'],
            false,
        ))->toDocument();
        $saved = (new StudioMachineCompositionResult(
            StudioMachineCompositionOperation::Save,
            (object) ['value' => null, 'revision' => 'r-2'],
            true,
        ))->toDocument();
        $bare = (new StudioMachineCompositionResult(
            StudioMachineCompositionOperation::Dependencies,
            new stdClass(),
            false,
        ))->toDocument();

        self::assertEquals(
            (object) [
                'operation' => 'load',
                'replayed' => false,
                'value' => (object) ['kind' => 'blueprint'],
                'revision' => 'initial-a',
            ],
            $loaded,
        );
        self::assertEquals(
            (object) ['operation' => 'save', 'replayed' => true, 'value' => null, 'revision' => 'r-2'],
            $saved,
        );
        self::assertEquals(
            (object) ['operation' => 'dependencies', 'replayed' => false, 'value' => null, 'revision' => null],
            $bare,
        );
    }

    /**
     * Malformed machine input and unusable compositions are refused before any session or host is reached.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedInputAndUnusableCompositionsAreRefusedBeforeTheHost(): void
    {
        $service = $this->compositionService(new RecordingAuditRecorder());
        $gateway = self::gateway($service);
        $machine = self::context(AuthenticatedSurface::Api);
        $argument = new stdClass();
        $cases = [
            'studio.machine/surface-refused' => static fn () => $gateway->open(
                self::context(AuthenticatedSurface::Administrator),
                self::$compositionTypeId,
                4,
            ),
            'studio.machine/target-invalid' => static fn () => $gateway->open($machine, self::$compositionTypeId, 0),
            'studio.composition/not-found' => static fn () => $gateway->open($machine, self::$compositionTypeId, 4),
            'studio.machine/idempotency-key-required' => static fn () => $gateway->perform(
                $machine,
                StudioMachineCompositionOperation::Save,
                'contexts/key',
                'session-one',
                $argument,
                'initial-a',
                null,
            ),
            'studio.machine/idempotency-key-unexpected' => static fn () => $gateway->perform(
                $machine,
                StudioMachineCompositionOperation::Load,
                'contexts/key',
                'session-one',
                $argument,
                null,
                'replay-key-0001',
            ),
            'studio.machine/idempotency-key-invalid' => static fn () => $gateway->perform(
                $machine,
                StudioMachineCompositionOperation::Publish,
                'contexts/key',
                'session-one',
                $argument,
                'initial-a',
                'not a key',
            ),
            'studio.machine/revision-invalid' => static fn () => $gateway->perform(
                $machine,
                StudioMachineCompositionOperation::Publish,
                'contexts/key',
                'session-one',
                $argument,
                str_repeat('r', 201),
                'replay-key-0001',
            ),
            'studio.machine/session-invalid' => static fn () => $gateway->perform(
                $machine,
                StudioMachineCompositionOperation::Load,
                'not a session',
                'session-one',
                $argument,
                null,
                null,
            ),
            'studio.machine/generation-invalid' => static fn () => $gateway->perform(
                $machine,
                StudioMachineCompositionOperation::Load,
                'contexts/key',
                '',
                $argument,
                null,
                null,
            ),
            'studio.machine/locale-invalid' => static fn () => $gateway->perform(
                $machine,
                StudioMachineCompositionOperation::Dependencies,
                'contexts/key',
                'session-one',
                $argument,
                null,
                null,
                'not a locale!',
            ),
        ];
        foreach ($cases as $diagnostic => $call) {
            $refused = self::refusal($call);
            self::assertSame([$diagnostic], $refused->diagnosticCodes(), $diagnostic);
        }
        $unreadable = self::refusal(
            static fn () => $gateway->open($machine, '018f22e2-7c8b-7ab0-8f3a-88e8026be999', 4),
        );
        self::assertSame(['not-found', ['studio.composition/not-found']], [
            $unreadable->category(),
            $unreadable->diagnosticCodes(),
        ]);

        $service->provision(
            AuthorizationContext::human(['content.read']),
            self::$compositionTypeId,
            4,
            StudioContentCompositionService::RENDERERS,
        );
        $this->retheme();
        $stale = self::refusal(static fn () => $gateway->open($machine, self::$compositionTypeId, 4));

        self::assertSame(['conflict', ['studio.composition/theme-mismatch']], [
            $stale->category(),
            $stale->diagnosticCodes(),
        ]);
    }

    /**
     * Build a gateway whose session authority and host factory must never be reached by these refusals.
     *
     * @param   StudioContentCompositionService  $compositions  Real composition service over in-memory stores.
     *
     * @return  StudioMachineCompositionGateway  Gateway under test.
     *
     * @since   2.0.0
     */
    private static function gateway(StudioContentCompositionService $compositions): StudioMachineCompositionGateway
    {
        return new StudioMachineCompositionGateway(
            $compositions,
            (new ReflectionClass(StudioHostSessionAuthority::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(StudioProducerHostFactory::class))->newInstanceWithoutConstructor(),
        );
    }

    /**
     * A Blueprint-capable context on one surface.
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
            ['content.read', 'studio.mode.blueprint'],
            'api-token:composition-gateway',
        )->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'composition-gateway-request',
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
