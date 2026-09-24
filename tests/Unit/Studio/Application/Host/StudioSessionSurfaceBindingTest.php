<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextBinding;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Host\StudioSessionSurfaceBinding;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins which surfaces may hold a Studio binding, what each binds to, and that a binding never crosses surfaces.
 *
 * A machine surface binds to its exact credential and may open only a Content authoring session; the browser
 * keeps its session binding; portal, background and recovery contexts hold nothing. A session opened by one
 * credential or surface is refused, without disclosure, to every other.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioSessionSurfaceBinding::class)]
#[CoversClass(StudioHostSessionAuthority::class)]
#[CoversClass(StudioProducerRequestAuthority::class)]
#[CoversClass(ContentStudioAuthoringContextBinding::class)]
#[CoversClass(AuthenticatedPrincipal::class)]
final class StudioSessionSurfaceBindingTest extends TestCase
{
    /**
     * The browser and the three machine surfaces are admitted; nothing else is.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyTheAdministratorAndMachineSurfacesHoldABinding(): void
    {
        foreach (AuthenticatedSurface::cases() as $surface) {
            $machine = in_array(
                $surface,
                [AuthenticatedSurface::Api, AuthenticatedSurface::Cli, AuthenticatedSurface::Mcp],
                true,
            );
            self::assertSame($machine, StudioSessionSurfaceBinding::isMachine($surface), $surface->value);
            self::assertSame(
                $machine || $surface === AuthenticatedSurface::Administrator,
                StudioSessionSurfaceBinding::admits($surface),
                $surface->value,
            );
        }
    }

    /**
     * A machine binds to its credential only; the browser to its session; the rest to nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDigestFollowsTheCredentialOrTheBrowserSession(): void
    {
        $first = self::machine(['content.read'], 'api-token:one', 'request-one');
        $sameCredential = self::machine(['content.read', 'content.update'], 'api-token:one', 'request-two');
        $otherCredential = self::machine(['content.read'], 'api-token:two', 'request-one');

        self::assertSame(hash('sha256', 'credential:api-token:one'), StudioSessionSurfaceBinding::digest($first));
        self::assertSame(
            StudioSessionSurfaceBinding::digest($first),
            StudioSessionSurfaceBinding::digest($sameCredential),
        );
        self::assertNotSame(
            StudioSessionSurfaceBinding::digest($first),
            StudioSessionSurfaceBinding::digest($otherCredential),
        );
        self::assertSame(
            hash('sha256', 'browser-session'),
            StudioSessionSurfaceBinding::digest(self::browser('browser-session')),
        );
        self::assertNull(StudioSessionSurfaceBinding::digest(self::browser(null)));
        self::assertNull(StudioSessionSurfaceBinding::digest(self::machine(
            ['content.read'],
            'api-token:one',
            'request-one',
            AuthenticatedSurface::Portal,
        )));
    }

    /**
     * A machine opens only Content authoring, and its session resolves only under its own credential and surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMachineSessionsAreConfinedToContentAuthoringAndTheirCredential(): void
    {
        $authority = self::authority();
        $capabilities = ['studio.mode.hybrid', 'studio.mode.content', 'content.update'];
        $api = self::machine($capabilities, 'api-token:one', 'request-one');

        $snapshot = $authority->open(
            $api,
            StudioSessionMode::Hybrid,
            StudioResourceKind::ContentAuthoring,
            'contexts/' . str_repeat('a', 64),
        );
        self::assertSame(AuthenticatedSurface::Api->value, $snapshot->session->surface);
        self::assertSame(hash('sha256', 'credential:api-token:one'), $snapshot->session->sessionBinding);
        $key = $snapshot->session->resourceContextKey;
        $again = $authority->resolve(self::machine($capabilities, 'api-token:one', 'request-two'), $key);
        self::assertSame($snapshot->generation, $again->generation);

        foreach (
            [
                'other credential' => self::machine($capabilities, 'api-token:two', 'request-one'),
                'other surface' => self::machine(
                    $capabilities,
                    'api-token:one',
                    'request-one',
                    AuthenticatedSurface::Cli,
                ),
                'browser' => self::browser('browser-session', $capabilities),
            ] as $label => $context
        ) {
            try {
                $authority->resolve($context, $key);
                self::fail($label . ' must not resolve a machine session.');
            } catch (StudioHostAccessRefused $refused) {
                self::assertSame('studio.host/context-refused', $refused->diagnosticCode, $label);
            }
        }

        try {
            $authority->open($api, StudioSessionMode::Content, StudioResourceKind::Content, 'content-1');
            self::fail('A machine surface must not open a plain Content session.');
        } catch (StudioHostAccessRefused $refused) {
            self::assertSame('studio.host/session-refused', $refused->diagnosticCode);
        }
        try {
            $authority->open(
                self::machine($capabilities, 'api-token:one', 'r', AuthenticatedSurface::Portal),
                StudioSessionMode::Hybrid,
                StudioResourceKind::ContentAuthoring,
                'contexts/' . str_repeat('b', 64),
            );
            self::fail('A portal context must not open a Studio session.');
        } catch (StudioHostAccessRefused $refused) {
            self::assertSame('forbidden', $refused->category);
        }
    }

    /**
     * A context binding admits machine surfaces and refuses surfaces that hold no Studio binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAContextBindingAcceptsMachineSurfacesAndRefusesOthers(): void
    {
        $target = new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Create,
            null,
            null,
            null,
            null,
            null,
            '/administrator/content/new',
        );
        $binding = static fn (string $surface): ContentStudioAuthoringContextBinding =>
            new ContentStudioAuthoringContextBinding(
                'contexts/' . str_repeat('c', 64),
                AuthorizationContext::SUBJECT,
                SiteContext::DEFAULT,
                null,
                null,
                $surface,
                str_repeat('d', 64),
                str_repeat('e', 64),
                $target,
                new DateTimeImmutable('2026-09-24T10:00:00Z'),
                new DateTimeImmutable('2026-09-24T11:00:00Z'),
            );

        foreach (['administrator', 'api', 'cli', 'mcp'] as $surface) {
            self::assertSame($surface, $binding($surface)->surface);
        }
        foreach (['portal', 'background', 'recovery', 'unknown'] as $surface) {
            try {
                $binding($surface);
                self::fail($surface . ' must not hold a Studio authoring context.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * The request authority reports a replay only after the mutation boundary notes one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRequestAuthorityCarriesReplayEvidence(): void
    {
        $authority = new StudioProducerRequestAuthority(self::browser('browser-session'), self::authority());

        self::assertFalse($authority->replayed());
        $authority->noteReplay();
        self::assertTrue($authority->replayed());
    }

    /**
     * Build a host authority over in-memory bindings and sequential keys.
     *
     * @return  StudioHostSessionAuthority  Production authority with deterministic doubles.
     *
     * @since   2.0.0
     */
    private static function authority(): StudioHostSessionAuthority
    {
        $repository = new class implements StudioHostSessionRepository {
            /**
             * Stored bindings by key.
             *
             * @var    array<string, StudioHostSession>
             * @since  2.0.0
             */
            private array $sessions = [];

            /**
             * Store one binding.
             *
             * @param   StudioHostSession  $session  Binding to store.
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
             * Find one binding by key.
             *
             * @param   string  $resourceContextKey  Opaque key.
             *
             * @return  StudioHostSession|null  Stored binding, if any.
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
             * Next key suffix.
             *
             * @var    int
             * @since  2.0.0
             */
            private int $next = 1;

            /**
             * Allocate the next deterministic key.
             *
             * @return  string  Canonical key.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/surface-test-' . $this->next++;
            }
        };

        return new StudioHostSessionAuthority(AuthorizationContext::gateway(), $repository, $keys);
    }

    /**
     * A credentialed machine context.
     *
     * @param   list<string>          $capabilities  Granted capabilities.
     * @param   string                $credential    Credential identity.
     * @param   string                $requestId     Request identity.
     * @param   AuthenticatedSurface  $surface       Surface the context authenticated through.
     *
     * @return  ExecutionContext  Bearer-strength context.
     *
     * @since   2.0.0
     */
    private static function machine(
        array $capabilities,
        string $credential,
        string $requestId,
        AuthenticatedSurface $surface = AuthenticatedSurface::Api,
    ): ExecutionContext {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            $capabilities,
            $credential,
        )->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            $requestId,
            surface: $surface,
        );
    }

    /**
     * An administrator browser context, optionally without a session.
     *
     * @param   ?string       $sessionId     Browser session identity, or null.
     * @param   list<string>  $capabilities  Granted capabilities.
     *
     * @return  ExecutionContext  Password-strength administrator context.
     *
     * @since   2.0.0
     */
    private static function browser(?string $sessionId, array $capabilities = ['content.read']): ExecutionContext
    {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            $capabilities,
            'api-token:one',
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'browser-request',
            surface: AuthenticatedSurface::Administrator,
            sessionId: $sessionId,
        );
    }
}
