<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use JsonException;
use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioPreviewGrantMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Preview\StudioPreviewActivityRecorder;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewDraftSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewHostPort;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRefused;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewTransportGuard;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewIdentity;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderedDocument;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioPreviewRepository;
use Kumwe\App\Studio\Infrastructure\Transport\NativeStudioPreviewSequenceWaiter;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;

/**
 * Pins the exact PreviewPort HTTP wrapper and validates emitted payloads against the released schema.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioPreviewHostPort::class)]
#[CoversClass(StudioPreviewRenderRequest::class)]
final class StudioPreviewHostPortTest extends TestCase
{
    /**
     * Render accepts only `{payload}` and cancel accepts only `{draftDigest}` around raw corpus arguments.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed preview vector is invalid.
     *
     * @since   2.0.0
     */
    public function testExactTransportWrappersAndRenderedProtocolShape(): void
    {
        $vector = self::vector();
        self::assertInstanceOf(stdClass::class, $vector->draft);
        self::assertInstanceOf(stdClass::class, $vector->render);
        $draft = new StudioPreviewDraft('default', $vector->draft);
        [$port, $guard, $snapshot, $context] = self::runtime($draft);
        $transport = static fn (int $sequence): StudioPreviewTransport => new StudioPreviewTransport(
            'https://kumwe.test',
            $guard->channelId($snapshot->session),
            $guard->sourceId($snapshot->session),
            $sequence,
        );

        $rendered = $port->dispatch(
            $context,
            'render',
            self::hostRequest('studio.operation/preview.render', (object) ['payload' => $vector->render]),
            $snapshot,
            $transport(0),
        );
        self::assertInstanceOf(stdClass::class, $rendered);
        $message = (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'preview-message',
            'channelId' => $guard->channelId($snapshot->session),
            'sessionGeneration' => $snapshot->generation,
            'sequence' => 0,
            'type' => 'studio.preview/rendered',
            'payload' => $rendered,
        ];
        self::assertTrue(StudioContractSchemas::fromVendoredCorpus()->validator('preview-message')->validate($message));

        self::assertRefused(
            'studio.preview/request-replayed',
            fn () => $port->dispatch(
                $context,
                'render',
                self::hostRequest('studio.operation/preview.render', (object) ['payload' => $vector->render]),
                $snapshot,
                $transport(1),
            ),
        );
        $wrongDigest = clone $vector->render;
        $wrongDigest->draftDigest = str_repeat('0', 64);
        self::assertRefused(
            'studio.preview/draft-identity-mismatch',
            fn () => $port->dispatch(
                $context,
                'render',
                self::hostRequest('studio.operation/preview.render', (object) ['payload' => $wrongDigest]),
                $snapshot,
                $transport(2),
            ),
        );
        self::assertRefused(
            'studio.host/invalid-arguments',
            fn () => $port->dispatch(
                $context,
                'render',
                self::hostRequest('studio.operation/preview.render', $vector->render),
                $snapshot,
                $transport(3),
            ),
        );
        self::assertRefused(
            'studio.host/invalid-arguments',
            fn () => $port->dispatch(
                $context,
                'render',
                self::hostRequest('studio.operation/preview.render', (object) ['request' => $vector->render]),
                $snapshot,
                $transport(4),
            ),
        );

        $cancelled = $port->dispatch(
            $context,
            'cancel',
            self::hostRequest(
                'studio.operation/preview.cancel',
                (object) ['draftDigest' => $vector->render->draftDigest],
            ),
            $snapshot,
            $transport(5),
        );
        self::assertNull($cancelled);
        self::assertRefused(
            'studio.host/invalid-arguments',
            fn () => $port->dispatch(
                $context,
                'cancel',
                self::hostRequest('studio.operation/preview.cancel', $vector->render->draftDigest),
                $snapshot,
                $transport(6),
            ),
        );
    }

    /**
     * A typed live-theme mismatch becomes a conflict and leaves no claimable pending grant.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed preview vector is invalid.
     *
     * @since   2.0.0
     */
    public function testThemeLockMismatchMapsToConflictAndAbandonsTheGrant(): void
    {
        $vector = self::vector();
        self::assertInstanceOf(stdClass::class, $vector->draft);
        self::assertInstanceOf(stdClass::class, $vector->render);
        $draft = new StudioPreviewDraft('default', $vector->draft);
        $renderer = new class implements StudioPreviewRenderer {
            /**
             * Refuse rendering because the exact public-theme lock is stale.
             *
             * @param   StudioHostSessionSnapshot   $snapshot  Trusted live session.
             * @param   StudioPreviewDraft          $draft     Immutable preview draft.
             * @param   StudioPreviewRenderRequest  $request   Exact preview request identity.
             * @param   StudioPreviewBindingValues  $values    Authorized binding projection.
             *
             * @return  StudioPreviewRenderedDocument
             *
             * @throws  StudioCompositionThemeMismatch  Always, to exercise the typed boundary.
             *
             * @since   2.0.0
             */
            public function render(
                StudioHostSessionSnapshot $snapshot,
                StudioPreviewDraft $draft,
                StudioPreviewRenderRequest $request,
                StudioPreviewBindingValues $values,
            ): StudioPreviewRenderedDocument {
                throw new StudioCompositionThemeMismatch();
            }
        };
        [$port, $guard, $snapshot, $context, $database] = self::runtime($draft, $renderer);
        $transport = new StudioPreviewTransport(
            'https://kumwe.test',
            $guard->channelId($snapshot->session),
            $guard->sourceId($snapshot->session),
            0,
        );

        try {
            $port->dispatch(
                $context,
                'render',
                self::hostRequest('studio.operation/preview.render', (object) ['payload' => $vector->render]),
                $snapshot,
                $transport,
            );
            self::fail('A stale Studio public-theme lock was rendered.');
        } catch (StudioPreviewRefused $refused) {
            self::assertSame('conflict', $refused->category);
            self::assertSame('studio.preview/theme-lock-mismatch', $refused->diagnosticCode);
        }

        self::assertSame('failed', $database->fetchOne(
            'SELECT state FROM kumwe_studio_preview_grants WHERE resource_context_key = ? AND request_id = ?',
            [$snapshot->session->resourceContextKey, $vector->render->requestId],
        ));
    }

    /**
     * Assemble the production port around real persistence and deterministic test edges.
     *
     * @param   StudioPreviewDraft      $draft     Immutable preview draft resolved by the source double.
     * @param   ?StudioPreviewRenderer  $renderer  Optional renderer used to exercise a refusal boundary.
     *
     * @return  array{StudioPreviewHostPort, StudioPreviewTransportGuard, StudioHostSessionSnapshot,
     *          ExecutionContext, Connection}
     *          Preview service, transport guard, trusted session, context, and persistence connection.
     *
     * @since   2.0.0
     */
    private static function runtime(StudioPreviewDraft $draft, ?StudioPreviewRenderer $renderer = null): array
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        (new StudioPreviewGrantMigration($tables))->up($database);
        $repository = new DoctrineStudioPreviewRepository($database, $tables);
        $guard = new StudioPreviewTransportGuard(
            'https://kumwe.test',
            $repository,
            new NativeStudioPreviewSequenceWaiter(),
        );
        $snapshot = self::snapshot($draft->artifactId());
        $source = new class ($draft) implements StudioPreviewDraftSource {
            /**
             * Retain the immutable draft returned by this deterministic source.
             *
             * @param  StudioPreviewDraft  $draft  Immutable preview draft.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly StudioPreviewDraft $draft)
            {
            }

            /**
             * Resolve the retained immutable draft.
             *
             * @param   StudioHostSessionSnapshot   $snapshot  Trusted live session.
             * @param   StudioPreviewRenderRequest  $request   Exact preview request identity.
             *
             * @return  StudioPreviewDraft|null  Retained draft.
             *
             * @since   2.0.0
             */
            public function find(
                StudioHostSessionSnapshot $snapshot,
                StudioPreviewRenderRequest $request,
            ): ?StudioPreviewDraft {
                return hash_equals($request->artifactId, $this->draft->artifactId()) ? $this->draft : null;
            }
        };
        $bindings = new class implements StudioPreviewBindingSource {
            /**
             * Return an empty authorized binding projection for the transport test.
             *
             * @param   ExecutionContext           $context   Trusted actor context.
             * @param   StudioHostSessionSnapshot  $snapshot  Trusted live session.
             * @param   StudioPreviewDraft         $draft     Immutable preview draft.
             *
             * @return  StudioPreviewBindingValues  Empty authorized projection.
             *
             * @since   2.0.0
             */
            public function resolve(
                ExecutionContext $context,
                StudioHostSessionSnapshot $snapshot,
                StudioPreviewDraft $draft,
            ): StudioPreviewBindingValues {
                return new StudioPreviewBindingValues(new stdClass(), new stdClass());
            }
        };
        $renderer ??= new class implements StudioPreviewRenderer {
            /**
             * Render deterministic marker-complete preview output.
             *
             * @param   StudioHostSessionSnapshot   $snapshot  Trusted live session.
             * @param   StudioPreviewDraft          $draft     Immutable preview draft.
             * @param   StudioPreviewRenderRequest  $request   Exact preview request identity.
             * @param   StudioPreviewBindingValues  $values    Authorized binding projection.
             *
             * @return  StudioPreviewRenderedDocument  Deterministic preview document.
             *
             * @since   2.0.0
             */
            public function render(
                StudioHostSessionSnapshot $snapshot,
                StudioPreviewDraft $draft,
                StudioPreviewRenderRequest $request,
                StudioPreviewBindingValues $values,
            ): StudioPreviewRenderedDocument {
                $identity = StudioPreviewIdentity::forDraft($draft->document());

                return new StudioPreviewRenderedDocument(
                    '<!doctype html><title>Preview</title>',
                    $identity['markers'],
                    $identity['markerMap'],
                );
            }
        };
        $activity = new class implements StudioPreviewActivityRecorder {
            /**
             * Accept one bounded preview activity record.
             *
             * @param   ExecutionContext           $context   Trusted actor context.
             * @param   StudioHostSessionSnapshot  $snapshot  Trusted live session.
             * @param   string                     $action    Closed preview action.
             * @param   string                     $outcome   Closed activity outcome.
             * @param   string                     $reason    Stable bounded reason.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function record(
                ExecutionContext $context,
                StudioHostSessionSnapshot $snapshot,
                string $action,
                string $outcome,
                string $reason,
            ): void {
            }
        };
        $clock = new class implements ClockInterface {
            /**
             * Return the deterministic preview test time.
             *
             * @return  DateTimeImmutable  Fixed UTC time.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-24T12:00:00+00:00');
            }
        };

        return [
            new StudioPreviewHostPort(
                $source,
                $bindings,
                $renderer,
                $repository,
                $guard,
                $activity,
                $clock,
            ),
            $guard,
            $snapshot,
            self::context(),
            $database,
        ];
    }

    /**
     * Build one canonical host request with a caller-supplied exact argument wrapper.
     *
     * @param   string  $operation  Exact Studio operation identifier.
     * @param   mixed   $arguments  Exact operation argument wrapper.
     *
     * @return  StudioHostRequest  Preview host request.
     *
     * @since   2.0.0
     */
    private static function hostRequest(string $operation, mixed $arguments): StudioHostRequest
    {
        return new StudioHostRequest(
            $operation,
            '0.1.0-draft.2',
            'requests/' . str_replace('.', '-', $operation),
            'contexts/preview-port',
            'session-preview-port',
            $arguments,
            null,
            null,
            null,
            null,
        );
    }

    /**
     * Assert a malformed wrapper is refused with one stable code.
     *
     * @param   string    $code      Expected stable diagnostic code.
     * @param   callable  $callback  Preview invocation expected to fail.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertRefused(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail('The malformed Studio preview wrapper was accepted.');
        } catch (StudioPreviewRefused $refused) {
            self::assertSame($code, $refused->diagnosticCode);
        }
    }

    /**
     * Build a trusted read-authorized Blueprint session for the vector artifact.
     *
     * @param   string  $artifactId  Exact Blueprint artifact identifier.
     *
     * @return  StudioHostSessionSnapshot  Live preview authority.
     *
     * @since   2.0.0
     */
    private static function snapshot(string $artifactId): StudioHostSessionSnapshot
    {
        $session = new StudioHostSession(
            'contexts/preview-port',
            'actor-preview',
            'default',
            null,
            null,
            'administrator',
            hash('sha256', 'preview-port-session'),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            $artifactId,
            'session-preview-port',
        );

        return new StudioHostSessionSnapshot(
            $session,
            ['studio.permission/read'],
            $session->sessionGeneration,
            true,
            false,
            false,
        );
    }

    /**
     * Mint the trusted administrator context passed independently from the Studio envelope.
     *
     * @return  ExecutionContext  Provenance-bound preview request authority.
     *
     * @since   2.0.0
     */
    private static function context(): ExecutionContext
    {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['studio.read'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-preview-port-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-test',
        );
    }

    /**
     * Load the exact canonical-preorder preview vector.
     *
     * @return  stdClass  Decoded vector.
     *
     * @throws  JsonException  When the committed fixture is invalid.
     * @throws  RuntimeException  When it does not decode to an object.
     *
     * @since   2.0.0
     */
    private static function vector(): stdClass
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/Studio/testkit/vectors/preview/canonical-preorder.json';
        $vector = json_decode((string) file_get_contents($path), false, 64, JSON_THROW_ON_ERROR);
        if (!$vector instanceof stdClass) {
            throw new RuntimeException('The Studio preview vector is invalid.');
        }

        return $vector;
    }
}
