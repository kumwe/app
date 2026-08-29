<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioMutationReplayRace;
use Kumwe\App\Studio\Application\Host\StudioMutationReplayRepository;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Host\StudioProducerMutationBoundary;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioMutationReplayRecord;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Infrastructure\Host\SodiumStudioMutationOutcomeCodec;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Operation;
use Kumwe\Producer\Wire\OperationRegistry;
use Kumwe\Producer\Wire\RequestEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * Proves App mutation, audit, replay protection, and secret rehydration form one Producer boundary.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioProducerMutationBoundary::class)]
final class StudioProducerMutationBoundaryTest extends TestCase
{
    /**
     * An unkeyed mutation still runs with the same authoritative transaction and audit guarantee.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testUnkeyedMutationAlwaysUsesTheTransactionAndAuditBoundary(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/telemetry.emit',
            (object) ['event' => (object) ['name' => 'kumwe.app/test']],
        );
        [$boundary, $transactions, $replays, $audit] = $this->boundary($authority);
        $calls = 0;

        $result = $boundary->execute(
            $operation,
            $request,
            null,
            null,
            static function () use (&$calls): HostResult {
                $calls++;

                return new HostResult(null);
            },
        );

        self::assertNull($result->intentDigest);
        self::assertInstanceOf(HostResult::class, $result->outcome());
        self::assertSame(1, $calls);
        self::assertSame(1, $transactions->calls);
        self::assertCount(1, $audit->events);
        self::assertSame([], $replays->records);
    }

    /**
     * A committed HostError is sealed and replayed without invoking the mutation a second time.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCommittedStateFailureIsProtectedAndReplayedExactlyOnce(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'bounded']],
            'idempotency/committed-failure',
        );
        [$boundary, $transactions, $replays, $audit] = $this->boundary($authority);
        $scope = CanonicalJson::digest((object) ['scope' => 'committed-failure']);
        $intent = CanonicalJson::digest((object) ['intent' => 'committed-failure']);
        $expected = StudioProducerError::error(
            'conflict',
            'studio.media/terminal-upload-failure',
            'revision-current',
        );
        $calls = 0;
        $mutation = static function () use (&$calls, $expected): HostError {
            $calls++;

            return $expected;
        };

        $first = $boundary->execute($operation, $request, $scope, $intent, $mutation);
        $second = $boundary->execute($operation, $request, $scope, $intent, $mutation);

        self::assertInstanceOf(HostError::class, $first->outcome());
        self::assertInstanceOf(HostError::class, $second->outcome());
        self::assertSame($expected->toCanonicalJson(), $first->outcome()->toCanonicalJson());
        self::assertSame($expected->toCanonicalJson(), $second->outcome()->toCanonicalJson());
        self::assertSame(1, $calls);
        self::assertSame(1, $transactions->calls);
        self::assertCount(1, $audit->events);
        self::assertCount(1, $replays->records);
    }

    /**
     * Upload capabilities never enter replay storage and are restored only from verified current authority.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAuthorizeUploadReplayRedactsAndRehydratesTheLiveToken(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/media.authorize-upload',
            (object) ['request' => (object) ['name' => 'asset.png']],
            'idempotency/upload-grant',
            ['content.update', 'studio.mode.content'],
        );
        $media = $this->createMock(StudioMediaOperations::class);
        $media->expects(self::once())
            ->method('replayUploadGrant')
            ->willReturnCallback(static function (
                ExecutionContext $context,
                StudioHostSessionSnapshot $snapshot,
                stdClass $stored,
            ): stdClass {
                unset($context, $snapshot);
                self::assertInstanceOf(stdClass::class, $stored->headers ?? null);
                self::assertFalse(property_exists($stored->headers, 'X-Studio-Upload-Token'));
                $restored = clone $stored;
                $restored->headers = clone $stored->headers;
                $restored->headers->{'X-Studio-Upload-Token'} = 'restored-live-token';

                return $restored;
            });
        [$boundary, , $replays, $audit, $codec] = $this->boundary($authority, $media);
        $scope = CanonicalJson::digest((object) ['scope' => 'upload-grant']);
        $intent = CanonicalJson::digest((object) ['intent' => 'upload-grant']);
        $grant = (object) [
            'headers' => (object) [
                'Content-Type' => 'image/png',
                'X-Studio-Upload-Token' => 'fresh-live-token',
            ],
            'method' => 'PUT',
            'uploadId' => 'uploads/producer-test',
            'url' => '/administrator/studio/media/uploads/producer-test',
        ];
        $calls = 0;
        $mutation = static function () use (&$calls, $grant): HostResult {
            $calls++;

            return new HostResult($grant);
        };

        $fresh = $boundary->execute($operation, $request, $scope, $intent, $mutation)->outcome();
        self::assertInstanceOf(HostResult::class, $fresh);
        self::assertSame('fresh-live-token', $fresh->value->headers->{'X-Studio-Upload-Token'});
        $record = $replays->onlyRecord();
        self::assertNotNull($record->protectedOutcome);
        $stored = $codec->recover($record->protectedOutcome, $record->scopeDigest, $record->intentDigest);
        self::assertInstanceOf(HostResult::class, $stored);
        self::assertInstanceOf(stdClass::class, $stored->value->headers ?? null);
        self::assertFalse(property_exists($stored->value->headers, 'X-Studio-Upload-Token'));

        $replayed = $boundary->execute($operation, $request, $scope, $intent, $mutation)->outcome();
        self::assertInstanceOf(HostResult::class, $replayed);
        self::assertSame('restored-live-token', $replayed->value->headers->{'X-Studio-Upload-Token'});
        self::assertSame(1, $calls);
        self::assertCount(1, $audit->events);
    }

    /**
     * Compose the boundary around observable deterministic in-memory services.
     *
     * @param   StudioProducerRequestAuthority  $authority  Successfully authorized request authority.
     * @param   StudioMediaOperations|null      $media      Optional upload rehydration authority.
     *
     * @return  array{StudioProducerMutationBoundary, object, object, object, SodiumStudioMutationOutcomeCodec}
     *
     * @since  2.0.0
     */
    private function boundary(
        StudioProducerRequestAuthority $authority,
        ?StudioMediaOperations $media = null,
    ): array {
        $transactions = new class implements TransactionManager {
            /** Number of transaction scopes opened. */
            public int $calls = 0;

            /** {@inheritDoc} */
            public function transactional(callable $operation): mixed
            {
                $this->calls++;

                return $operation();
            }

            /** {@inheritDoc} */
            public function afterCommit(callable $operation): void
            {
                $operation();
            }

            /** {@inheritDoc} */
            public function afterRollback(callable $operation): void
            {
                unset($operation);
            }
        };
        $replays = new class implements StudioMutationReplayRepository {
            /** @var array<string, StudioMutationReplayRecord> */
            public array $records = [];

            /** {@inheritDoc} */
            public function findReplay(string $scopeDigest): ?StudioMutationReplayRecord
            {
                return $this->records[$scopeDigest] ?? null;
            }

            /** {@inheritDoc} */
            public function beginReplay(
                StudioMutationReplayRecord $record,
                StudioHostSessionSnapshot $snapshot,
                \Kumwe\Producer\Wire\RequestContext $request,
            ): void {
                unset($snapshot, $request);
                if (isset($this->records[$record->scopeDigest])) {
                    throw new StudioMutationReplayRace('Duplicate in-memory replay claim.');
                }
                $this->records[$record->scopeDigest] = $record;
            }

            /** {@inheritDoc} */
            public function completeReplay(string $scopeDigest, string $protectedOutcome): void
            {
                $record = $this->records[$scopeDigest] ?? null;
                if ($record === null) {
                    throw new \LogicException('Missing in-memory replay claim.');
                }
                $this->records[$scopeDigest] = new StudioMutationReplayRecord(
                    $record->scopeDigest,
                    $record->intentDigest,
                    $protectedOutcome,
                );
            }

            /** Return the only completed test record. */
            public function onlyRecord(): StudioMutationReplayRecord
            {
                if (count($this->records) !== 1) {
                    throw new \LogicException('Expected exactly one in-memory replay record.');
                }

                return array_values($this->records)[0];
            }
        };
        $audit = new class implements AuditRecorder {
            /** @var list<AuditEvent> */
            public array $events = [];

            /** {@inheritDoc} */
            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $clock = new class implements ClockInterface {
            /** {@inheritDoc} */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-29T12:00:00+00:00');
            }
        };
        $codec = new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32));

        return [
            new StudioProducerMutationBoundary(
                $transactions,
                $replays,
                $codec,
                $audit,
                $clock,
                $media ?? $this->createStub(StudioMediaOperations::class),
                $authority,
            ),
            $transactions,
            $replays,
            $audit,
            $codec,
        ];
    }

    /**
     * Open and authorize one exact Producer mutation request.
     *
     * @param   string        $capability      Closed Producer operation capability.
     * @param   mixed         $arguments       Operation argument value.
     * @param   string|null   $idempotencyKey  Optional replay key.
     * @param   list<string>  $capabilities    Effective App grants.
     *
     * @return  array{Operation, RequestEnvelope, StudioProducerRequestAuthority}
     *
     * @since  2.0.0
     */
    private function authorizedRequest(
        string $capability,
        mixed $arguments,
        ?string $idempotencyKey = null,
        array $capabilities = ['studio.mode.content'],
    ): array {
        [$sessions, $context, $snapshot] = $this->session($capabilities);
        $operation = OperationRegistry::byCapability($capability);
        $requestContext = (object) [
            'operationId' => $capability,
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'requestId' => 'requests/mutation-boundary-test',
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'sessionGeneration' => $snapshot->generation,
        ];
        if ($idempotencyKey !== null) {
            $requestContext->idempotencyKey = $idempotencyKey;
        }
        $request = RequestEnvelope::parse(CanonicalJson::stringify((object) [
            'arguments' => $arguments,
            'context' => $requestContext,
        ]));
        $authority = new StudioProducerRequestAuthority($context, $sessions);
        self::assertNull($authority->authorize($operation, $request));

        return [$operation, $request, $authority];
    }

    /**
     * Assemble one deterministic open Studio session.
     *
     * @param   list<string>  $capabilities  Effective App grants.
     *
     * @return  array{StudioHostSessionAuthority, ExecutionContext, StudioHostSessionSnapshot}
     *
     * @since  2.0.0
     */
    private function session(array $capabilities): array
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
                return 'contexts/producer-mutation-test';
            }
        };
        $sessions = new StudioHostSessionAuthority(AuthorizationContext::gateway(), $repository, $keys);
        $context = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            $capabilities,
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-producer-mutation-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-test',
        );
        $snapshot = $sessions->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-producer-mutation',
        );

        return [$sessions, $context, $snapshot];
    }
}
