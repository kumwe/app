<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioHostDispatcher;
use Kumwe\App\Studio\Application\Host\StudioHostRequestDecoder;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Replays the AP-3 permission and envelope vectors and proves the shared stale fence precedes all ports.
 *
 * Covered upstream vector IDs are explicit in {@see hostVectors()} for AP-1 replay accounting:
 * `vector.host-vector.permission.explain.withheld`, `vector.host-vector.permission.refresh.snapshot`,
 * `vector.host-vector.envelope.malformed-context`, `vector.host-vector.envelope.protocol-version`, and
 * `vector.host-vector.envelope.stale-generation`.
 *
 * @since 2.0.0
 */
#[CoversClass(StudioHostDispatcher::class)]
#[CoversClass(StudioHostRequestDecoder::class)]
final class StudioHostDispatcherTest extends TestCase
{
    /**
     * Name the exact vendored AP-3 vectors replayed by this test class.
     *
     * @return  iterable<string, array{string}>  Vector ID to fixture filename arguments.
     *
     * @since   2.0.0
     */
    public static function hostVectors(): iterable
    {
        yield 'vector.host-vector.permission.explain.withheld' => ['permission.explain.withheld.json'];
        yield 'vector.host-vector.permission.refresh.snapshot' => ['permission.refresh.snapshot.json'];
        yield 'vector.host-vector.envelope.malformed-context' => ['envelope.malformed-context.error.json'];
        yield 'vector.host-vector.envelope.protocol-version' => ['envelope.protocol-version.error.json'];
        yield 'vector.host-vector.envelope.stale-generation' => ['envelope.stale-generation.error.json'];
    }

    /**
     * Each vendored permission or envelope fixture drives the matching canonical response branch.
     *
     * @param   string  $filename  Vendored host-vector fixture filename.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('hostVectors')]
    public function testVendoredPermissionAndEnvelopeVectorsDriveCanonicalOutcomes(string $filename): void
    {
        [$dispatcher, $authority, $context] = $this->runtime(['studio.mode.content']);
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-vector',
        );
        $vector = self::vector($filename);
        assert(is_string($vector->id));
        assert($vector->context instanceof stdClass);
        assert(is_string($vector->context->operationId));
        assert($vector->expect instanceof stdClass);
        assert(is_string($vector->expect->outcome));
        self::assertSame(
            'vector.host-vector.' . str_replace('.json', '', str_replace('.error', '', $filename)),
            $vector->id,
        );
        $document = self::requestFromVector($vector);
        $document->context->resourceContextKey = $snapshot->session->resourceContextKey;
        if (!str_starts_with($filename, 'envelope.stale-generation')) {
            $document->context->sessionGeneration = $snapshot->generation;
        }
        [$port, $operation] = self::route($vector->context->operationId);

        $outcome = $dispatcher->dispatch($context, $port, $operation, $document);
        self::assertTrue(
            StudioContractSchemas::fromVendoredCorpus()
                ->validator($outcome->status === 200 ? 'host-result' : 'host-error')
                ->validate($outcome->document),
            $vector->id,
        );

        if ($vector->expect->outcome === 'result') {
            self::assertSame(200, $outcome->status);
            self::assertTrue(property_exists($outcome->document, 'value'));
            if ($vector->expect->value === 'permission-snapshot') {
                self::assertSame($snapshot->generation, $outcome->document->value->sessionGeneration);
                self::assertSame($snapshot->permissions, $outcome->document->value->permissions);
            } else {
                self::assertInstanceOf(stdClass::class, $outcome->document->value);
                self::assertFalse($outcome->document->value->allowed);
                self::assertSame('studio.permission/withheld', $outcome->document->value->reason->key);
            }
        } else {
            assert(is_string($vector->expect->category));
            self::assertSame($vector->expect->category, $outcome->document->category);
            self::assertFalse($outcome->document->retryable);
        }
    }

    /**
     * The stale-generation fence runs before every one of the 24 canonical later operations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryCanonicalLaterOperationIsFencedByTheSameStaleGenerationDiagnostic(): void
    {
        [$dispatcher, $authority, $context] = $this->runtime(['studio.mode.content']);
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-stale',
        );

        foreach (self::operationCapabilities() as $operationId) {
            [$port, $operation] = self::route($operationId);
            $request = (object) ['context' => (object) [
                'operationId' => $operationId,
                'protocolVersion' => StudioHostDispatcher::PROTOCOL_VERSION,
                'requestId' => 'requests/stale-' . str_replace(['studio.operation/', '.'], ['', '-'], $operationId),
                'resourceContextKey' => $snapshot->session->resourceContextKey,
                'sessionGeneration' => 'session-obsolete',
            ]];

            $outcome = $dispatcher->dispatch($context, $port, $operation, $request);

            self::assertTrue(
                StudioContractSchemas::fromVendoredCorpus()->validator('host-error')->validate($outcome->document),
                $operationId,
            );
            self::assertSame('invalid-request', $outcome->document->category, $operationId);
            self::assertSame(
                'studio.host/stale-session-generation',
                $outcome->document->diagnostics[0]->code,
                $operationId,
            );
        }
    }

    /**
     * Closed schema validation refuses client-asserted actor and capability members before dispatch.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testActorAndCapabilitySpoofingAreRejectedByTheClosedEnvelopeBeforeDispatch(): void
    {
        [$dispatcher, $authority, $context] = $this->runtime(['studio.mode.content']);
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-spoof',
        );
        $base = self::permissionRequest($snapshot->session->resourceContextKey, $snapshot->generation);
        $base->context->actor = 'forged';
        $base->capabilities = ['studio.permission/edit-blueprint'];

        $outcome = $dispatcher->dispatch($context, 'permission', 'refresh', $base);

        self::assertSame('invalid-request', $outcome->document->category);
        self::assertSame('studio.host/invalid-request', $outcome->document->diagnostics[0]->code);
        self::assertStringNotContainsString('forged', $outcome->document->message->defaultMessage);
    }

    /**
     * Permission operations return deterministic snapshots independent of correlation and retry metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPermissionRefreshAndExplainAreDeterministicAndIdempotencyDoesNotChangeAuthority(): void
    {
        [$dispatcher, $authority, $context] = $this->runtime([
            'content.publish',
            'studio.mode.content',
        ]);
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-permission',
        );
        $request = (object) [
            'arguments' => (object) ['operation' => 'studio.permission/publish'],
            'context' => (object) [
                'idempotencyKey' => 'idempotency/permission-1',
                'operationId' => 'studio.operation/permission.explain',
                'protocolVersion' => StudioHostDispatcher::PROTOCOL_VERSION,
                'requestId' => 'requests/permission-1',
                'resourceContextKey' => $snapshot->session->resourceContextKey,
                'sessionGeneration' => $snapshot->generation,
            ],
        ];

        $first = $dispatcher->dispatch($context, 'permission', 'explain', $request);
        $request->context->requestId = 'requests/permission-2';
        $second = $dispatcher->dispatch($context, 'permission', 'explain', $request);

        self::assertEquals($first->document, $second->document);
        self::assertTrue($first->document->value->allowed);
        $refreshRequest = self::permissionRequest($snapshot->session->resourceContextKey, $snapshot->generation);
        $refreshRequest->arguments = new stdClass();
        $refresh = $dispatcher->dispatch(
            $context,
            'permission',
            'refresh',
            $refreshRequest,
        );
        self::assertSame(200, $refresh->status);
        self::assertSame($snapshot->generation, $refresh->document->value->sessionGeneration);

        $refreshRequest->arguments = (object) ['unexpected' => true];
        $refused = $dispatcher->dispatch($context, 'permission', 'refresh', $refreshRequest);
        self::assertSame('studio.host/invalid-arguments', $refused->document->diagnostics[0]->code);
    }

    /**
     * Revoking exact mode authority invalidates the generation without exposing the policy reason.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRevokedModeMakesTheNextPermissionCallStaleRatherThanLeakingPolicy(): void
    {
        [$dispatcher, $authority, $context] = $this->runtime(['studio.mode.content']);
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-revoked',
        );
        $revoked = self::context([]);

        $outcome = $dispatcher->dispatch(
            $revoked,
            'permission',
            'refresh',
            self::permissionRequest($snapshot->session->resourceContextKey, $snapshot->generation),
        );

        self::assertSame('invalid-request', $outcome->document->category);
        self::assertSame('studio.host/stale-session-generation', $outcome->document->diagnostics[0]->code);
        self::assertStringNotContainsString('grant', $outcome->document->message->defaultMessage);
    }

    /**
     * Revoking only unpublish authority invalidates a session even while the shared protocol permission remains.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRevokedLifecycleAuthorityMakesTheNextPermissionCallStaleWithoutDisclosure(): void
    {
        [$dispatcher, $authority, $context] = $this->runtime([
            'content.publish',
            'content.unpublish',
            'studio.mode.content',
        ]);
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-lifecycle-revoked',
        );
        self::assertTrue($snapshot->canPublish);
        self::assertTrue($snapshot->canUnpublish);
        self::assertContains('studio.permission/publish', $snapshot->permissions);
        $unpublishRevoked = self::context(['content.publish', 'studio.mode.content']);

        $outcome = $dispatcher->dispatch(
            $unpublishRevoked,
            'permission',
            'refresh',
            self::permissionRequest($snapshot->session->resourceContextKey, $snapshot->generation),
        );

        self::assertSame('invalid-request', $outcome->document->category);
        self::assertSame('studio.host/stale-session-generation', $outcome->document->diagnostics[0]->code);
        self::assertStringNotContainsString('unpublish', $outcome->document->message->defaultMessage);
        self::assertStringNotContainsString('grant', $outcome->document->message->defaultMessage);
    }

    /**
     * Assemble a deterministic permission-port runtime around production application services.
     *
     * @param  list<string>  $capabilities  Global capability grants carried by the actor.
     *
     * @return  array{StudioHostDispatcher, StudioHostSessionAuthority, ExecutionContext}
     *          Dispatcher, authority service and trusted context.
     *
     * @since  2.0.0
     */
    private function runtime(array $capabilities): array
    {
        $repository = new class implements StudioHostSessionRepository {
            /**
             * Sessions retained by opaque resource-context key.
             *
             * @var    array<string, StudioHostSession>
             * @since  2.0.0
             */
            private array $sessions = [];

            /**
             * Retain one binding under its opaque key.
             *
             * @param   StudioHostSession  $session  Binding opened by the authority under test.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(StudioHostSession $session): void
            {
                $this->sessions[$session->resourceContextKey] = $session;
            }
            /**
             * Resolve one retained binding by opaque key.
             *
             * @param   string  $resourceContextKey  Opaque key to resolve.
             *
             * @return  StudioHostSession|null  Retained binding, or null.
             *
             * @since   2.0.0
             */
            public function find(string $resourceContextKey): ?StudioHostSession
            {
                return $this->sessions[$resourceContextKey] ?? null;
            }
        };
        $keys = new class implements StudioResourceContextKeyFactory {
            /**
             * Return the deterministic key used by one dispatcher runtime.
             *
             * @return  string  Canonical test context key.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/dispatcher-test';
            }
        };
        $authority = new StudioHostSessionAuthority(AuthorizationContext::gateway(), $repository, $keys);
        $schemas = StudioContractSchemas::fromVendoredCorpus();

        return [
            new StudioHostDispatcher(new StudioHostRequestDecoder($schemas), $authority),
            $authority,
            self::context($capabilities),
        ];
    }

    /**
     * Mint a trusted administrator context with the exact global grants under test.
     *
     * @param   list<string>  $capabilities  Global capability grants.
     *
     * @return  ExecutionContext  Provenance-bound administrator context.
     *
     * @since   2.0.0
     */
    private static function context(array $capabilities): ExecutionContext
    {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            $capabilities,
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-dispatcher-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-test',
        );
    }

    /**
     * Build a canonical permission-refresh request for one open session.
     *
     * @param   string  $key         Opaque resource-context key.
     * @param   string  $generation  Current or deliberately stale session generation.
     *
     * @return  stdClass  Canonical host-request document.
     *
     * @since   2.0.0
     */
    private static function permissionRequest(string $key, string $generation): stdClass
    {
        return (object) ['context' => (object) [
            'operationId' => 'studio.operation/permission.refresh',
            'protocolVersion' => StudioHostDispatcher::PROTOCOL_VERSION,
            'requestId' => 'requests/permission-refresh',
            'resourceContextKey' => $key,
            'sessionGeneration' => $generation,
        ]];
    }

    /**
     * Decode one exact vendored host vector without copying its expectations.
     *
     * @param   string  $filename  Fixture filename inside the pinned host vector directory.
     *
     * @return  stdClass  Decoded canonical vector.
     *
     * @since   2.0.0
     */
    private static function vector(string $filename): stdClass
    {
        $document = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/Fixtures/Studio/testkit/vectors/host/' . $filename),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $document);

        return $document;
    }

    /**
     * Project a vector's canonical context and optional argument into the HTTP request shape.
     *
     * @param   stdClass  $vector  Decoded vendored host vector.
     *
     * @return  stdClass  Canonical host request driven by the fixture.
     *
     * @since   2.0.0
     */
    private static function requestFromVector(stdClass $vector): stdClass
    {
        $request = new stdClass();
        if (property_exists($vector, 'argument')) {
            $request->arguments = $vector->argument;
        }
        assert($vector->context instanceof stdClass);
        $request->context = clone $vector->context;

        return $request;
    }

    /**
     * Derive normative route segments from a canonical operation capability.
     *
     * @param   string  $operationId  Canonical operation capability.
     *
     * @return  array{string, string}  Port and operation route segments.
     *
     * @since   2.0.0
     */
    private static function route(string $operationId): array
    {
        $wire = substr($operationId, strlen('studio.operation/'));
        $parts = explode('.', $wire, 2);
        self::assertCount(2, $parts);

        return [$parts[0], $parts[1]];
    }

    /**
     * Read all operation capabilities from the pinned schema enum.
     *
     * @return  list<string>  Closed canonical operation capability vocabulary.
     *
     * @since   2.0.0
     */
    private static function operationCapabilities(): array
    {
        $schema = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 5) . '/resources/studio-contract/protocol/schemas/host-operations.schema.json',
            ),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $schema);
        $definitions = $schema->{'$defs'};
        self::assertInstanceOf(stdClass::class, $definitions);
        $operationCapability = $definitions->operationCapability;
        self::assertInstanceOf(stdClass::class, $operationCapability);
        $operations = $operationCapability->enum;
        self::assertIsArray($operations);
        $result = [];
        foreach ($operations as $operation) {
            self::assertIsString($operation);
            $result[] = $operation;
        }

        return $result;
    }
}
