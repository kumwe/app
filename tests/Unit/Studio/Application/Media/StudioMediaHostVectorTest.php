<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Media;

use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Application\Idempotency\IdempotencyLedger;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Media\StudioMediaCursorCodec;
use Kumwe\App\Studio\Application\Media\StudioMediaHostPort;
use Kumwe\App\Studio\Application\Media\StudioMediaMutationIdempotency;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Studio\Application\Media\StudioMediaPortRejected;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Media\StudioExternalUrlPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaPolicyRejected;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * Replays all ten vendored media host vectors through the exact HTTP wrapper binding.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMediaHostPort::class)]
#[CoversClass(StudioMediaCursorCodec::class)]
#[CoversClass(StudioMediaPortRejected::class)]
final class StudioMediaHostVectorTest extends TestCase
{
    /**
     * Discover exactly the ten pinned media host vectors.
     *
     * @return iterable<string, array{string}>
     *
     * @since  2.0.0
     */
    public static function vectors(): iterable
    {
        $paths = glob(dirname(__DIR__, 4) . '/Fixtures/Studio/testkit/vectors/host/media.*.json');
        self::assertIsArray($paths);
        self::assertCount(10, $paths);
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $vector = self::decode($path);
            self::assertIsString($vector->id);
            yield $vector->id => [$path];
        }
    }

    /**
     * Adapt each raw vector argument to the production HTTP adapter's exact wrapper and assert its outcome.
     *
     * @param   string  $path  Absolute vendored host vector path.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    #[DataProvider('vectors')]
    public function testVendoredHostVector(string $path): void
    {
        $vector = self::decode($path);
        self::assertIsString($vector->id);
        self::assertIsString($vector->operation);
        self::assertInstanceOf(stdClass::class, $vector->argument);
        self::assertInstanceOf(stdClass::class, $vector->context);
        self::assertInstanceOf(stdClass::class, $vector->expect);
        self::assertInstanceOf(stdClass::class, $vector->given);
        self::assertIsString($vector->context->operationId);
        self::assertIsString($vector->context->protocolVersion);
        self::assertIsString($vector->context->requestId);
        self::assertIsString($vector->context->resourceContextKey);
        self::assertIsString($vector->context->sessionGeneration);
        $context = self::context();
        $snapshot = self::snapshot($context, $vector->context, $vector->given);
        $request = new StudioHostRequest(
            $vector->context->operationId,
            $vector->context->protocolVersion,
            $vector->context->requestId,
            $vector->context->resourceContextKey,
            $vector->context->sessionGeneration,
            self::wrapper($vector->operation, $vector->argument),
            null,
            null,
            null,
            null,
        );
        /** @var IdempotencyLedger&Stub $ledger */
        $ledger = self::createStub(IdempotencyLedger::class);
        /** @var TransactionManager&Stub $transactions */
        $transactions = self::createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $port = new StudioMediaHostPort(
            $this->operations(),
            new StudioMediaMutationIdempotency(
                $ledger,
                $transactions,
                self::createStub(ClockInterface::class),
            ),
        );

        $outcome = $port->dispatch($context, $request, $snapshot);

        self::assertIsString($vector->expect->outcome);
        if ($vector->expect->outcome === 'result') {
            self::assertSame(200, $outcome->status, $vector->id);
            if ($vector->expect->value === 'null') {
                self::assertNull($outcome->document->value, $vector->id);
            } else {
                self::assertInstanceOf(stdClass::class, $outcome->document->value, $vector->id);
            }
        } else {
            self::assertIsString($vector->expect->category);
            self::assertSame($vector->expect->category, $outcome->document->category, $vector->id);
            self::assertFalse($outcome->document->retryable, $vector->id);
        }
        $encoded = json_encode($outcome->document, JSON_THROW_ON_ERROR);
        $forbiddenValues = $vector->expect->messageMustNotContain ?? [];
        self::assertIsArray($forbiddenValues);
        foreach ($forbiddenValues as $forbidden) {
            self::assertIsString($forbidden);
            self::assertStringNotContainsString($forbidden, $encoded, $vector->id);
        }
    }

    /**
     * Refuse unknown operations and every malformed HTTP-adapter wrapper before invoking media custody.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testUnknownOperationsAndMalformedWrappersAreRefused(): void
    {
        $context = self::context();
        $snapshot = self::directSnapshot($context, [
            'studio.permission/read',
            'studio.permission/upload-media',
        ]);
        $cases = [
            'unknown operation' => [
                'studio.operation/media.unknown',
                new stdClass(),
                'studio.host/operation-unavailable',
            ],
            'invalid upload descriptor' => [
                'studio.operation/media.authorize-upload',
                (object) ['request' => (object) ['filename' => 'one.png']],
                'studio.media/upload-failed',
            ],
            'extra wrapper member' => [
                'studio.operation/media.get',
                (object) ['assetId' => 'assets/one', 'extra' => true],
                'studio.host/invalid-arguments',
            ],
            'non-object query' => [
                'studio.operation/media.list',
                (object) ['query' => 'all'],
                'studio.host/invalid-arguments',
            ],
            'invalid stable identity' => [
                'studio.operation/media.get',
                (object) ['assetId' => 'bad identity'],
                'studio.host/invalid-arguments',
            ],
        ];
        $port = $this->port();

        foreach ($cases as $case => [$operation, $arguments, $code]) {
            $outcome = $port->dispatch(
                $context,
                self::request($operation, $arguments),
                $snapshot,
            );
            self::assertSame($code, $outcome->document->diagnostics[0]->code, $case);
        }
    }

    /**
     * Require read authority for every operation and upload authority for every media mutation.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testReadAndMutationPermissionsAreIndependentlyRequired(): void
    {
        $context = self::context();
        $port = $this->port();
        $cases = [
            'missing read' => [
                self::request('studio.operation/media.get', (object) ['assetId' => 'assets/one']),
                [],
            ],
            'missing upload' => [
                self::request(
                    'studio.operation/media.authorize-upload',
                    (object) ['request' => (object) [
                        'byteSize' => 128,
                        'filename' => 'one.jpg',
                        'mediaType' => 'image/jpeg',
                        'purpose' => 'studio.media/content',
                    ]],
                ),
                ['studio.permission/read'],
            ],
        ];

        foreach ($cases as $case => [$request, $permissions]) {
            $outcome = $port->dispatch(
                $context,
                $request,
                self::directSnapshot($context, $permissions),
            );
            self::assertSame(403, $outcome->status, $case);
            self::assertSame(
                'studio.media/permission-refused',
                $outcome->document->diagnostics[0]->code,
                $case,
            );
        }
    }

    /**
     * Compose the host port around deterministic operation and transaction doubles.
     *
     * @return  StudioMediaHostPort  Ready media host adapter.
     *
     * @since  2.0.0
     */
    private function port(): StudioMediaHostPort
    {
        $ledger = self::createStub(IdempotencyLedger::class);
        $transactions = self::createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new StudioMediaHostPort(
            $this->operations(),
            new StudioMediaMutationIdempotency(
                $ledger,
                $transactions,
                self::createStub(ClockInterface::class),
            ),
        );
    }

    /**
     * Build one direct host request around caller-controlled operation arguments.
     *
     * @param   string  $operation  Canonical or deliberately unknown operation identifier.
     * @param   mixed   $arguments  HTTP-adapter argument candidate.
     *
     * @return  StudioHostRequest  Request envelope.
     *
     * @since  2.0.0
     */
    private static function request(string $operation, mixed $arguments): StudioHostRequest
    {
        return new StudioHostRequest(
            $operation,
            '0.1.0-draft.2',
            'requests/media-refusal',
            'contexts/media-refusal',
            'generation-media-refusal',
            $arguments,
            null,
            null,
            null,
            null,
        );
    }

    /**
     * Build a trusted live snapshot with the exact supplied Studio permissions.
     *
     * @param   ExecutionContext  $context      Trusted App context.
     * @param   list<string>      $permissions  Studio permission set.
     *
     * @return  StudioHostSessionSnapshot  Matching live snapshot.
     *
     * @since  2.0.0
     */
    private static function directSnapshot(ExecutionContext $context, array $permissions): StudioHostSessionSnapshot
    {
        sort($permissions, SORT_STRING);
        $session = new StudioHostSession(
            'contexts/media-refusal',
            $context->actorId(),
            $context->site()->identifier(),
            null,
            null,
            'administrator',
            str_repeat('a', 64),
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-refusal',
            'generation-media-refusal',
        );

        return new StudioHostSessionSnapshot(
            $session,
            $permissions,
            'generation-media-refusal',
            true,
            false,
            false,
        );
    }

    /**
     * Supply a deterministic semantic double behind the production wrapper decoder.
     *
     * @return  StudioMediaOperations  Operations needed by the canonical vector corpus.
     *
     * @since  2.0.0
     */
    private function operations(): StudioMediaOperations
    {
        /** @var StudioMediaOperations&Stub $operations */
        $operations = self::createStub(StudioMediaOperations::class);
        $operations->method('authorizeUpload')->willReturnCallback(
            static function (
                ExecutionContext $context,
                StudioHostSessionSnapshot $snapshot,
                StudioMediaUploadRequest $request,
            ): stdClass {
                $policy = new StudioMediaUploadPolicy(['image/jpeg'], 16_777_216, false);
                try {
                    $plan = $policy->authorize($request);
                } catch (StudioMediaPolicyRejected $failure) {
                    throw new StudioMediaPortRejected(
                        $failure->failureCode === 'studio.media/upload-too-large'
                            ? 'limit-exceeded'
                            : 'validation-failed',
                        $failure->failureCode,
                    );
                }

                return (object) [
                    'expiresAt' => '2030-01-01T00:00:00.000Z',
                    'method' => 'PUT',
                    'plan' => $plan->document(),
                    'uploadId' => 'uploads/vector-grant',
                    'url' => 'https://uploads.example.invalid/vector-grant',
                ];
            },
        );
        $operations->method('abortUpload')->willThrowException(
            new StudioMediaPortRejected('not-found', 'studio.media/upload-not-found'),
        );
        $operations->method('completeUpload')->willThrowException(
            new StudioMediaPortRejected('not-found', 'studio.media/upload-not-found'),
        );
        $operations->method('get')->willReturn(null);
        $operations->method('list')->willReturnCallback(static function (
            ExecutionContext $context,
            stdClass $query,
        ): stdClass {
            if (!is_int($query->limit ?? null) || $query->limit < 1 || $query->limit > 100) {
                throw new StudioMediaPortRejected('invalid-request', 'studio.media/query-invalid');
            }
            if (property_exists($query, 'cursor')) {
                self::assertIsString($query->cursor);
                (new StudioMediaCursorCodec(str_repeat('k', 32)))->decode(
                    $query->cursor,
                    'default',
                    hash('sha256', '{"mediaTypes":[],"search":""}'),
                );
            }

            return (object) ['assets' => []];
        });
        $operations->method('importExternal')->willReturnCallback(static function (
            ExecutionContext $context,
            string $url,
        ): stdClass {
            if (!(new StudioExternalUrlPolicy())->validate($url)->acceptedUrl()) {
                throw new StudioMediaPortRejected(
                    'validation-failed',
                    'studio.media/external-url-refused',
                );
            }

            return (object) ['id' => 'assets/vector', 'revision' => 'r1', 'state' => 'ready'];
        });
        $operations->method('uploadStatus')->willThrowException(
            new StudioMediaPortRejected('not-found', 'studio.media/asset-not-found'),
        );

        return $operations;
    }

    /**
     * Project raw vector arguments into `http-host-adapter.ts` wire wrappers.
     *
     * @param   string    $operation  Canonical media operation name.
     * @param   stdClass  $argument   Raw language-neutral vector argument.
     *
     * @return  stdClass  Exact HTTP adapter arguments.
     *
     * @since  2.0.0
     */
    private static function wrapper(string $operation, stdClass $argument): stdClass
    {
        return match ($operation) {
            'authorize-upload' => (object) ['request' => clone $argument],
            'list' => (object) ['query' => clone $argument],
            default => clone $argument,
        };
    }

    /**
     * Mint a trusted administrator context for the adapter boundary.
     *
     * @return  ExecutionContext  Provenance-bound test context.
     *
     * @since  2.0.0
     */
    private static function context(): ExecutionContext
    {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['content.read', 'content.update', 'studio.mode.content'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-media-vector',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-media-vector',
        );
    }

    /**
     * Build the already resolved trusted snapshot supplied by the central dispatcher.
     *
     * @param   ExecutionContext  $context Trusted App context.
     * @param   stdClass          $wire    Vendored canonical host context.
     * @param   stdClass          $given   Vendored semantic setup.
     *
     * @return  StudioHostSessionSnapshot  Live matching media authority.
     *
     * @since  2.0.0
     */
    private static function snapshot(
        ExecutionContext $context,
        stdClass $wire,
        stdClass $given,
    ): StudioHostSessionSnapshot {
        assert(is_string($wire->resourceContextKey));
        assert(is_string($wire->sessionGeneration));
        assert(is_array($given->permissions));
        foreach ($given->permissions as $permission) {
            assert(is_string($permission));
        }
        /** @var list<string> $vectorPermissions */
        $vectorPermissions = array_values($given->permissions);
        $permissions = array_values(array_unique(array_merge(
            $vectorPermissions,
            ['studio.permission/read', 'studio.permission/upload-media'],
        )));
        sort($permissions, SORT_STRING);
        $session = new StudioHostSession(
            $wire->resourceContextKey,
            $context->actorId(),
            $context->site()->identifier(),
            null,
            null,
            'administrator',
            str_repeat('a', 64),
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-vector',
            $wire->sessionGeneration,
        );

        return new StudioHostSessionSnapshot(
            $session,
            $permissions,
            $wire->sessionGeneration,
            true,
            false,
            false,
        );
    }

    /**
     * Decode one vendored media host vector.
     *
     * @param   string  $path  Absolute fixture path.
     *
     * @return  stdClass  Decoded vector.
     *
     * @since  2.0.0
     */
    private static function decode(string $path): stdClass
    {
        $document = json_decode((string) file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $document);

        return $document;
    }
}
