<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use LogicException;
use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Wire\Dispatcher;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\MutationOutcome;
use Kumwe\Producer\Wire\Operation;
use Kumwe\Producer\Wire\OperationRegistry;
use Kumwe\Producer\Wire\Port\ArtifactPortInterface;
use Kumwe\Producer\Wire\Port\AuthorizationInterface;
use Kumwe\Producer\Wire\Port\HostAdapterInterface;
use Kumwe\Producer\Wire\Port\LocalizationPortInterface;
use Kumwe\Producer\Wire\Port\MediaPortInterface;
use Kumwe\Producer\Wire\Port\ModelPortInterface;
use Kumwe\Producer\Wire\Port\MutationBoundaryInterface;
use Kumwe\Producer\Wire\Port\PermissionPortInterface;
use Kumwe\Producer\Wire\Port\PreviewPortInterface;
use Kumwe\Producer\Wire\Port\RecoveryPortInterface;
use Kumwe\Producer\Wire\Port\ResourcePortInterface;
use Kumwe\Producer\Wire\Port\TelemetryPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use Kumwe\Producer\Wire\RequestEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Proves the App resolves fresh trusted authority before any Producer port or replay boundary.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioProducerRequestAuthority::class)]
final class StudioProducerRequestAuthorityTest extends TestCase
{
    /**
     * The closed Producer operation and the envelope operation must be byte-identical.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testOperationCapabilityMustEqualTheEnvelopeOperation(): void
    {
        [$sessions, $context, $snapshot] = $this->runtime(['studio.mode.content']);
        $authority = new StudioProducerRequestAuthority($context, $sessions);
        $request = self::request(
            'studio.operation/permission.refresh',
            $snapshot,
            new stdClass(),
        );

        self::assertNull($authority->authorize(
            OperationRegistry::byCapability('studio.operation/permission.refresh'),
            $request,
        ));
        self::assertSame($snapshot->session, $authority->snapshot()->session);

        $refusal = $authority->authorize(
            OperationRegistry::byCapability('studio.operation/artifact.load'),
            $request,
        );
        self::assertNotNull($refusal);
        self::assertSame('invalid-request', $refusal->category());
        self::assertSame('studio.host/operation-mismatch', $refusal->diagnostics()[0]->code());
    }

    /**
     * A stale generation refuses the call and clears the previous successful snapshot.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testStaleGenerationRefusesAndNoSnapshotSurvivesAcrossCalls(): void
    {
        [$sessions, $context, $snapshot] = $this->runtime(['studio.mode.content']);
        $authority = new StudioProducerRequestAuthority($context, $sessions);
        $operation = OperationRegistry::byCapability('studio.operation/permission.refresh');

        self::assertNull($authority->authorize(
            $operation,
            self::request($operation->capability, $snapshot, new stdClass()),
        ));
        $refusal = $authority->authorize(
            $operation,
            self::request($operation->capability, $snapshot, new stdClass(), 'generation-stale'),
        );

        self::assertNotNull($refusal);
        self::assertSame('invalid-request', $refusal->category());
        self::assertSame('studio.host/stale-session-generation', $refusal->diagnostics()[0]->code());
        $this->expectException(LogicException::class);
        $authority->snapshot();
    }

    /**
     * Revoked live mode authority is checked before Producer may consult keyed replay state.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRevokedAuthorityStopsAKeyedMutationBeforeReplay(): void
    {
        [$sessions, , $snapshot] = $this->runtime(['studio.mode.content']);
        $revoked = self::context([]);
        $authority = new StudioProducerRequestAuthority($revoked, $sessions);
        $mutations = new class implements MutationBoundaryInterface {
            /** Number of replay-boundary calls observed. */
            public int $calls = 0;

            /** {@inheritDoc} */
            public function execute(
                Operation $operation,
                RequestEnvelope $request,
                ?string $scopeKey,
                ?string $intentDigest,
                callable $mutation,
            ): MutationOutcome {
                unset($operation, $request, $scopeKey, $intentDigest, $mutation);
                $this->calls++;
                throw new LogicException('Revoked authority reached the mutation boundary.');
            }
        };
        $artifact = new class implements ArtifactPortInterface {
            /** Whether a port operation was reached. */
            public bool $called = false;

            /** {@inheritDoc} */
            public function dependencies(mixed $arguments, RequestContext $context): HostResult
            {
                return $this->unexpected($arguments, $context);
            }

            /** {@inheritDoc} */
            public function load(mixed $arguments, RequestContext $context): HostResult
            {
                return $this->unexpected($arguments, $context);
            }

            /** {@inheritDoc} */
            public function publish(mixed $arguments, RequestContext $context): HostResult
            {
                return $this->unexpected($arguments, $context);
            }

            /** {@inheritDoc} */
            public function save(mixed $arguments, RequestContext $context): HostResult
            {
                return $this->unexpected($arguments, $context);
            }

            /** {@inheritDoc} */
            public function unpublish(mixed $arguments, RequestContext $context): HostResult
            {
                return $this->unexpected($arguments, $context);
            }

            /** Refuse any unexpected port call. */
            private function unexpected(mixed $arguments, RequestContext $context): never
            {
                unset($arguments, $context);
                $this->called = true;
                throw new LogicException('Revoked authority reached the artifact port.');
            }
        };
        $host = new class ($authority, $mutations, $artifact) implements HostAdapterInterface {
            /** Bind only the authorities and required port under test. */
            public function __construct(
                private AuthorizationInterface $authority,
                private MutationBoundaryInterface $mutations,
                private ArtifactPortInterface $artifact,
            ) {
            }

            /** {@inheritDoc} */
            public function authorization(): AuthorizationInterface
            {
                return $this->authority;
            }

            /** {@inheritDoc} */
            public function mutations(): MutationBoundaryInterface
            {
                return $this->mutations;
            }

            /** {@inheritDoc} */
            public function artifact(): ArtifactPortInterface
            {
                return $this->artifact;
            }

            /** {@inheritDoc} */
            public function localization(): ?LocalizationPortInterface
            {
                return null;
            }

            /** {@inheritDoc} */
            public function media(): ?MediaPortInterface
            {
                return null;
            }

            /** {@inheritDoc} */
            public function model(): ?ModelPortInterface
            {
                return null;
            }

            /** {@inheritDoc} */
            public function permission(): ?PermissionPortInterface
            {
                return null;
            }

            /** {@inheritDoc} */
            public function preview(): ?PreviewPortInterface
            {
                return null;
            }

            /** {@inheritDoc} */
            public function recovery(): ?RecoveryPortInterface
            {
                return null;
            }

            /** {@inheritDoc} */
            public function resource(): ?ResourcePortInterface
            {
                return null;
            }

            /** {@inheritDoc} */
            public function telemetry(): ?TelemetryPortInterface
            {
                return null;
            }
        };
        $request = self::requestDocument(
            'studio.operation/artifact.save',
            $snapshot,
            (object) ['document' => new stdClass()],
            expectedRevision: 'revision-1',
            idempotencyKey: 'idempotency/revoked-1',
        );

        $response = (new Dispatcher($host))->dispatch(
            'artifact/save',
            CanonicalJson::stringify($request),
        );

        self::assertSame('invalid-request', $response->refusalCategory);
        self::assertSame(0, $mutations->calls);
        self::assertFalse($artifact->called);
        $document = json_decode($response->body, false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $document);
        self::assertSame('studio.host/stale-session-generation', $document->diagnostics[0]->code);
    }

    /**
     * Assemble production session authority around deterministic in-memory stores.
     *
     * @param   list<string>  $capabilities  Initial trusted capabilities.
     *
     * @return  array{StudioHostSessionAuthority, ExecutionContext, StudioHostSessionSnapshot}
     *
     * @since  2.0.0
     */
    private function runtime(array $capabilities): array
    {
        $repository = new class implements StudioHostSessionRepository {
            /** @var array<string, StudioHostSession> */
            private array $sessions = [];

            /** {@inheritDoc} */
            public function add(StudioHostSession $session): void
            {
                $this->sessions[$session->resourceContextKey] = $session;
            }

            /** {@inheritDoc} */
            public function find(string $resourceContextKey): ?StudioHostSession
            {
                return $this->sessions[$resourceContextKey] ?? null;
            }
        };
        $keys = new class implements StudioResourceContextKeyFactory {
            /** {@inheritDoc} */
            public function create(): string
            {
                return 'contexts/producer-authority-test';
            }
        };
        $sessions = new StudioHostSessionAuthority(
            AuthorizationContext::gateway(),
            $repository,
            $keys,
        );
        $context = self::context($capabilities);
        $snapshot = $sessions->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-producer-authority',
        );

        return [$sessions, $context, $snapshot];
    }

    /**
     * Parse one exact Producer envelope for a trusted open session.
     *
     * @param   string                     $operationId      Closed Producer operation capability.
     * @param   StudioHostSessionSnapshot  $snapshot         Open trusted session.
     * @param   mixed                      $arguments        Operation argument value.
     * @param   string|null                $generation       Generation override under test.
     * @param   string|null                $expectedRevision Optional concurrency coordinate.
     * @param   string|null                $idempotencyKey   Optional replay coordinate.
     *
     * @return  RequestEnvelope  Parsed canonical Producer request.
     *
     * @since  2.0.0
     */
    private static function request(
        string $operationId,
        StudioHostSessionSnapshot $snapshot,
        mixed $arguments,
        ?string $generation = null,
        ?string $expectedRevision = null,
        ?string $idempotencyKey = null,
    ): RequestEnvelope {
        return RequestEnvelope::parse(CanonicalJson::stringify(self::requestDocument(
            $operationId,
            $snapshot,
            $arguments,
            $generation,
            $expectedRevision,
            $idempotencyKey,
        )));
    }

    /**
     * Build one exact Producer envelope document for dispatch or parsing.
     *
     * @param   string                     $operationId      Closed Producer operation capability.
     * @param   StudioHostSessionSnapshot  $snapshot         Open trusted session.
     * @param   mixed                      $arguments        Operation argument value.
     * @param   string|null                $generation       Generation override under test.
     * @param   string|null                $expectedRevision Optional concurrency coordinate.
     * @param   string|null                $idempotencyKey   Optional replay coordinate.
     *
     * @return  stdClass  Exact canonical envelope document.
     *
     * @since  2.0.0
     */
    private static function requestDocument(
        string $operationId,
        StudioHostSessionSnapshot $snapshot,
        mixed $arguments,
        ?string $generation = null,
        ?string $expectedRevision = null,
        ?string $idempotencyKey = null,
    ): stdClass {
        $context = (object) [
            'operationId' => $operationId,
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'requestId' => 'requests/producer-authority-test',
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'sessionGeneration' => $generation ?? $snapshot->generation,
        ];
        if ($expectedRevision !== null) {
            $context->expectedRevision = $expectedRevision;
        }
        if ($idempotencyKey !== null) {
            $context->idempotencyKey = $idempotencyKey;
        }

        return (object) [
            'arguments' => $arguments,
            'context' => $context,
        ];
    }

    /**
     * Mint a trusted administrator context with the selected live capabilities.
     *
     * @param   list<string>  $capabilities  Effective App grants.
     *
     * @return  ExecutionContext  Provenance-bound execution context.
     *
     * @since  2.0.0
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
            'studio-producer-authority-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-test',
        );
    }
}
