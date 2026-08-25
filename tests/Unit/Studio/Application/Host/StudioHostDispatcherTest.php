<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioHostDispatcher;
use Kumwe\App\Studio\Application\Host\StudioHostOperationRefused;
use Kumwe\App\Studio\Application\Host\StudioHostRequestDecoder;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Host\StudioResourceHostPort;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchItem;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchPage;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchProvider;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
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
 * @since  2.0.0
 */
#[CoversClass(StudioHostDispatcher::class)]
#[CoversClass(StudioHostRequestDecoder::class)]
#[CoversClass(StudioResourceHostPort::class)]
#[CoversClass(StudioResourceSearchItem::class)]
#[CoversClass(StudioResourceSearchPage::class)]
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
     * Closed validation refuses spoofed actor and capability members before dispatch.
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
     * Permission operations are deterministic and unavailable or malformed later routes fail closed.
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

        $request->arguments = new stdClass();
        $invalidExplain = $dispatcher->dispatch($context, 'permission', 'explain', $request);
        self::assertSame('invalid-request', $invalidExplain->document->category);
        self::assertSame(
            'studio.host/invalid-arguments',
            $invalidExplain->document->diagnostics[0]->code,
        );

        $previewRequest = (object) ['context' => (object) [
            'operationId' => 'studio.operation/preview.render',
            'protocolVersion' => StudioHostDispatcher::PROTOCOL_VERSION,
            'requestId' => 'requests/preview-without-transport',
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'sessionGeneration' => $snapshot->generation,
        ]];
        $invalidPreview = $dispatcher->dispatch($context, 'preview', 'render', $previewRequest);
        self::assertSame('invalid-request', $invalidPreview->document->category);
        self::assertSame(
            'studio.preview/invalid-transport',
            $invalidPreview->document->diagnostics[0]->code,
        );

        $artifactRequest = (object) ['context' => (object) [
            'operationId' => 'studio.operation/artifact.load',
            'protocolVersion' => StudioHostDispatcher::PROTOCOL_VERSION,
            'requestId' => 'requests/artifact-without-port',
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'sessionGeneration' => $snapshot->generation,
        ]];
        $unavailableArtifact = $dispatcher->dispatch($context, 'artifact', 'load', $artifactRequest);
        self::assertSame('incompatible', $unavailableArtifact->document->category);
        self::assertSame(
            'studio.host/operation-unavailable',
            $unavailableArtifact->document->diagnostics[0]->code,
        );
    }

    /**
     * Resource values accept bounded portable identities and refuse malformed or untyped data.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testResourceValueObjectsEnforceTheirPortableBounds(): void
    {
        $item = new StudioResourceSearchItem('content-entry:018f22e2', 'Über release notes');
        self::assertSame('content-entry:018f22e2', $item->id);
        self::assertSame('Über release notes', $item->label);
        self::assertSame([$item], (new StudioResourceSearchPage([$item], true))->items);
        self::assertSame(
            240,
            strlen((new StudioResourceSearchItem(str_repeat('a', 240), 'Boundary item'))->id),
        );

        foreach (
            [
                '',
                '/starts-with-a-separator',
                'contains a space',
                'contains~a-tilde',
                '__proto__',
                'prototype',
                'constructor',
                str_repeat('a', 241),
            ] as $id
        ) {
            self::assertInvalidArgument(
                static fn () => new StudioResourceSearchItem($id, 'Valid label'),
                'A Studio resource search item ID is invalid.',
            );
        }
        foreach (['', str_repeat('界', 501)] as $label) {
            self::assertInvalidArgument(
                static fn () => new StudioResourceSearchItem('content-entry:valid', $label),
                'A Studio resource search item label is invalid.',
            );
        }
        self::assertInvalidArgument(
            static function (): void {
                /** @phpstan-ignore argument.type (the runtime item guard is the subject) */
                new StudioResourceSearchPage([new stdClass()], false);
            },
            'A Studio resource search page contains an invalid item.',
        );
    }

    /**
     * Provider composition rejects malformed and ambiguous resource-family ownership.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testResourceProviderRegistryRejectsInvalidAndDuplicateFamilies(): void
    {
        $validBoundary = $this->createStub(StudioResourceSearchProvider::class);
        $validBoundary->method('resourceType')->willReturn(
            str_repeat('a', 79) . '/' . str_repeat('b', 80),
        );
        self::assertInstanceOf(StudioResourceHostPort::class, new StudioResourceHostPort([$validBoundary]));

        foreach (
            [
                'not-qualified',
                'kumwe_app/content-entry',
                'kumwe.app/content_entry',
                'Kumwe.app/content-entry',
                'kumwe..app/content-entry',
                'kumwe.app/-content-entry',
                str_repeat('a', 80) . '/' . str_repeat('b', 80),
            ] as $resourceType
        ) {
            $invalid = $this->createStub(StudioResourceSearchProvider::class);
            $invalid->method('resourceType')->willReturn($resourceType);
            self::assertInvalidArgument(
                static fn () => new StudioResourceHostPort([$invalid]),
                'A Studio resource search provider is invalid or duplicated.',
            );
        }

        $first = $this->createStub(StudioResourceSearchProvider::class);
        $first->method('resourceType')->willReturn('kumwe.app/content-entry');
        $second = $this->createStub(StudioResourceSearchProvider::class);
        $second->method('resourceType')->willReturn('kumwe.app/content-entry');
        self::assertInvalidArgument(
            static fn () => new StudioResourceHostPort([$first, $second]),
            'A Studio resource search provider is invalid or duplicated.',
        );
    }

    /**
     * Resource searches retain trusted context, serialize safe labels and advance only opaque cursors.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testResourcePortSerializesAuthorizedPagesAndOpaqueCursors(): void
    {
        [, $authority, $context] = $this->runtime(['studio.mode.content']);
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-resource-search',
        );
        $provider = $this->createMock(StudioResourceSearchProvider::class);
        $provider->expects(self::once())
            ->method('resourceType')
            ->willReturn('kumwe.app/content-entry');
        $provider->expects(self::exactly(2))
            ->method('search')
            ->willReturnCallback(static function (
                ExecutionContext $actualContext,
                string $search,
                int $offset,
                int $limit,
            ) use ($context): StudioResourceSearchPage {
                self::assertSame($context, $actualContext);
                self::assertSame('release', $search);
                self::assertSame(2, $limit);

                return match ($offset) {
                    0 => new StudioResourceSearchPage([
                        new StudioResourceSearchItem('content-entry:first', 'First release'),
                        new StudioResourceSearchItem('content-entry:second', 'Second release'),
                    ], true),
                    2 => new StudioResourceSearchPage([
                        new StudioResourceSearchItem('content-entry:third', 'Third release'),
                    ], false),
                    default => self::fail('The provider received a non-canonical cursor offset.'),
                };
            });
        $port = new StudioResourceHostPort([$provider]);

        $first = $port->dispatch(
            $context,
            'search',
            self::resourceHostRequest(self::resourceArguments('kumwe.app/content-entry', 'release', 2)),
            $snapshot,
        );
        self::assertInstanceOf(stdClass::class, $first->value);
        self::assertCount(2, $first->value->items);
        self::assertSame('content-entry:first', $first->value->items[0]->id);
        self::assertSame('kumwe.app/content-entry', $first->value->items[0]->resourceType);
        self::assertSame('kumwe.app/resource-label', $first->value->items[0]->label->key);
        self::assertSame('First release', $first->value->items[0]->label->defaultMessage);
        self::assertSame(base64_encode('index:2'), $first->value->nextCursor);
        self::assertStringNotContainsString('index', $first->value->nextCursor);

        $second = $port->dispatch(
            $context,
            'search',
            self::resourceHostRequest(self::resourceArguments(
                'kumwe.app/content-entry',
                'release',
                2,
                $first->value->nextCursor,
            )),
            $snapshot,
        );
        self::assertInstanceOf(stdClass::class, $second->value);
        self::assertSame('content-entry:third', $second->value->items[0]->id);
        self::assertFalse(property_exists($second->value, 'nextCursor'));

        $unknown = $port->dispatch(
            $context,
            'search',
            self::resourceHostRequest(self::resourceArguments('kumwe.app/private-resource', '', 10)),
            $snapshot,
        );
        self::assertInstanceOf(stdClass::class, $unknown->value);
        self::assertSame([], $unknown->value->items);
        self::assertFalse(property_exists($unknown->value, 'nextCursor'));
    }

    /**
     * Resource search rejects writes, open query shapes, forged cursors and over-producing providers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testResourcePortFailsClosedAcrossEveryUntrustedBoundary(): void
    {
        [, $authority, $context] = $this->runtime(['studio.mode.content']);
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-resource-refusal',
        );
        $provider = $this->createMock(StudioResourceSearchProvider::class);
        $provider->expects(self::once())
            ->method('resourceType')
            ->willReturn('kumwe.app/content-entry');
        $provider->expects(self::once())
            ->method('search')
            ->willReturn(new StudioResourceSearchPage([
                new StudioResourceSearchItem('content-entry:first', 'First'),
                new StudioResourceSearchItem('content-entry:second', 'Second'),
            ], false));
        $port = new StudioResourceHostPort([$provider]);
        $valid = self::resourceArguments('kumwe.app/content-entry', '', 1);
        $invalidArguments = [
            null,
            (object) ['query' => $valid->query, 'unexpected' => true],
            (object) ['query' => 'not-an-object'],
            (object) ['query' => (object) [
                'limit' => 1,
                'resourceType' => 'kumwe.app/content-entry',
                'unexpected' => true,
            ]],
            self::resourceArguments('kumwe.app/content-entry', '', 0),
            self::resourceArguments('not-qualified', '', 1),
            self::resourceArguments('kumwe.app/content-entry', str_repeat('x', 161), 1),
            self::resourceArguments('kumwe.app/content-entry', '', 1, 42),
        ];

        self::assertPortRefused(
            static fn () => $port->dispatch(
                $context,
                'unknown',
                self::resourceHostRequest($valid),
                $snapshot,
            ),
            'incompatible',
            'studio.host/operation-unavailable',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch(
                $context,
                'search',
                self::resourceHostRequest($valid, expectedRevision: 'revision/not-allowed'),
                $snapshot,
            ),
            'invalid-request',
            'studio.host/invalid-context',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch(
                $context,
                'search',
                self::resourceHostRequest($valid, idempotencyKey: 'idempotency/not-allowed'),
                $snapshot,
            ),
            'invalid-request',
            'studio.host/invalid-context',
        );
        foreach ($invalidArguments as $arguments) {
            self::assertPortRefused(
                static fn () => $port->dispatch(
                    $context,
                    'search',
                    self::resourceHostRequest($arguments),
                    $snapshot,
                ),
                'invalid-request',
                'studio.host/invalid-arguments',
            );
        }
        foreach (['not-base64', base64_encode('index:01'), base64_encode('index:4999901')] as $cursor) {
            self::assertPortRefused(
                static fn () => $port->dispatch(
                    $context,
                    'search',
                    self::resourceHostRequest(self::resourceArguments(
                        'kumwe.app/content-entry',
                        '',
                        1,
                        $cursor,
                    )),
                    $snapshot,
                ),
                'invalid-request',
                'studio.resource/invalid-cursor',
            );
        }
        self::assertPortRefused(
            static fn () => $port->dispatch(
                $context,
                'search',
                self::resourceHostRequest($valid),
                $snapshot,
            ),
            'internal',
            'studio.resource/provider-invalid',
        );
    }

    /**
     * The central dispatcher routes resource search after authority and maps every port refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDispatcherRoutesResourceSearchAfterItsSharedAuthorityFence(): void
    {
        $provider = $this->createMock(StudioResourceSearchProvider::class);
        $provider->expects(self::once())
            ->method('resourceType')
            ->willReturn('kumwe.app/content-entry');
        $provider->expects(self::once())
            ->method('search')
            ->willReturn(new StudioResourceSearchPage([
                new StudioResourceSearchItem('content-entry:visible', 'Visible content'),
            ], false));
        $resource = new StudioResourceHostPort([$provider]);
        [$dispatcher, $authority, $context] = $this->runtime(['studio.mode.content'], $resource);
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-dispatch-resource',
        );
        $request = self::resourceEnvelope(
            $snapshot->session->resourceContextKey,
            $snapshot->generation,
            self::resourceArguments('kumwe.app/content-entry', '', 10),
        );

        $result = $dispatcher->dispatch($context, 'resource', 'search', $request);
        self::assertSame(200, $result->status);
        self::assertSame('content-entry:visible', $result->document->value->items[0]->id);
        self::assertTrue(StudioContractSchemas::fromVendoredCorpus()->validator('host-result')->validate(
            $result->document,
        ));

        $request->context->requestId = 'requests/resource-invalid-cursor';
        $request->arguments->query->cursor = 'forged';
        $invalidCursor = $dispatcher->dispatch($context, 'resource', 'search', $request);
        self::assertSame(400, $invalidCursor->status);
        self::assertSame('invalid-request', $invalidCursor->document->category);
        self::assertSame(
            'studio.resource/invalid-cursor',
            $invalidCursor->document->diagnostics[0]->code,
        );

        $unavailable = (new StudioHostDispatcher(
            new StudioHostRequestDecoder(StudioContractSchemas::fromVendoredCorpus()),
            $authority,
        ))->dispatch($context, 'resource', 'search', self::resourceEnvelope(
            $snapshot->session->resourceContextKey,
            $snapshot->generation,
            self::resourceArguments('kumwe.app/content-entry', '', 10),
        ));
        self::assertSame(400, $unavailable->status);
        self::assertSame('incompatible', $unavailable->document->category);
        self::assertSame(
            'studio.host/operation-unavailable',
            $unavailable->document->diagnostics[0]->code,
        );

        $stale = $dispatcher->dispatch($context, 'resource', 'search', self::resourceEnvelope(
            $snapshot->session->resourceContextKey,
            'session-obsolete',
            self::resourceArguments('kumwe.app/content-entry', '', 10),
        ));
        self::assertSame('invalid-request', $stale->document->category);
        self::assertSame(
            'studio.host/stale-session-generation',
            $stale->document->diagnostics[0]->code,
        );
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
     * @param   list<string>                 $capabilities  Global capability grants carried by the actor.
     * @param   StudioResourceHostPort|null  $resource      Optional resource port under dispatcher test.
     *
     * @return  array{StudioHostDispatcher, StudioHostSessionAuthority, ExecutionContext}
     *          Dispatcher, authority service and trusted context.
     *
     * @since   2.0.0
     */
    private function runtime(array $capabilities, ?StudioResourceHostPort $resource = null): array
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
            new StudioHostDispatcher(
                new StudioHostRequestDecoder($schemas),
                $authority,
                resource: $resource,
            ),
            $authority,
            self::context($capabilities),
        ];
    }

    /**
     * Build exact resource-search arguments with optional cursor presence.
     *
     * @param   string      $resourceType  Qualified resource family selected from the Studio catalog.
     * @param   string      $search        Bounded human search text.
     * @param   int         $limit         Requested result-page size.
     * @param   mixed|null  $cursor        Optional opaque cursor or deliberate malformed candidate.
     *
     * @return  stdClass  Canonical resource-search argument object.
     *
     * @since   2.0.0
     */
    private static function resourceArguments(
        string $resourceType,
        string $search,
        int $limit,
        mixed $cursor = null,
    ): stdClass {
        $query = (object) [
            'limit' => $limit,
            'resourceType' => $resourceType,
            'search' => $search,
        ];
        if ($cursor !== null) {
            $query->cursor = $cursor;
        }

        return (object) ['query' => $query];
    }

    /**
     * Build a validated domain request for direct resource-port refusal and serialization tests.
     *
     * @param   mixed        $arguments         Candidate search arguments.
     * @param   string|null  $expectedRevision  Deliberate read-context revision when under test.
     * @param   string|null  $idempotencyKey    Deliberate read-context idempotency key when under test.
     *
     * @return  StudioHostRequest  Resource request with deterministic context coordinates.
     *
     * @since   2.0.0
     */
    private static function resourceHostRequest(
        mixed $arguments,
        ?string $expectedRevision = null,
        ?string $idempotencyKey = null,
    ): StudioHostRequest {
        return new StudioHostRequest(
            'studio.operation/resource.search',
            StudioHostDispatcher::PROTOCOL_VERSION,
            'requests/resource-search',
            'contexts/dispatcher-test',
            'session-r1',
            $arguments,
            $expectedRevision,
            $idempotencyKey,
            null,
            null,
        );
    }

    /**
     * Build one canonical wire request for resource dispatch through the closed decoder.
     *
     * @param   string    $key         Opaque trusted resource-context key.
     * @param   string    $generation  Current or deliberately stale authority generation.
     * @param   stdClass  $arguments   Exact resource-search arguments.
     *
     * @return  stdClass  Canonical host-request document.
     *
     * @since   2.0.0
     */
    private static function resourceEnvelope(
        string $key,
        string $generation,
        stdClass $arguments,
    ): stdClass {
        return (object) [
            'arguments' => $arguments,
            'context' => (object) [
                'operationId' => 'studio.operation/resource.search',
                'protocolVersion' => StudioHostDispatcher::PROTOCOL_VERSION,
                'requestId' => 'requests/resource-search',
                'resourceContextKey' => $key,
                'sessionGeneration' => $generation,
            ],
        ];
    }

    /**
     * Assert a value-object invariant without allowing a later malformed case to be skipped.
     *
     * @param   callable(): mixed  $operation  Construction expected to fail.
     * @param   string             $message    Exact stable exception message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertInvalidArgument(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('The malformed resource value unexpectedly succeeded.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * Assert one resource host-port refusal and its stable diagnostic.
     *
     * @param   callable(): mixed  $operation  Host-port call expected to fail closed.
     * @param   string             $category   Expected protocol error category.
     * @param   string             $code       Expected stable diagnostic code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertPortRefused(callable $operation, string $category, string $code): void
    {
        try {
            $operation();
            self::fail('The malformed resource host request unexpectedly succeeded.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame($category, $refused->category);
            self::assertSame($code, $refused->diagnosticCode);
        }
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
