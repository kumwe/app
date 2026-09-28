<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Decides which authenticated surfaces may hold a Studio host binding and what each one binds to.
 *
 * A Studio context or host session is never a bearer credential: every later request must reproduce the
 * trusted scope that opened it, including the one coordinate that outlives a single request. In the
 * administrator browser that coordinate is the rotated session identity. A machine caller — REST bearer,
 * management CLI or MCP — has no browser session, so it binds to the exact credential that authenticated
 * it instead. Both digests are one-way and are never disclosed, and the surface itself is stored beside
 * them, so a browser-opened context cannot be replayed from a token nor a token-opened one from a browser.
 * Portal, background and recovery surfaces hold no Studio binding at all.
 *
 * @since  2.0.0
 */
final readonly class StudioSessionSurfaceBinding
{
    /**
     * Machine surfaces that obtain a Studio authoring binding from their credential.
     *
     * @var    list<AuthenticatedSurface>
     * @since  2.0.0
     */
    public const array MACHINE_SURFACES = [
        AuthenticatedSurface::Api,
        AuthenticatedSurface::Cli,
        AuthenticatedSurface::Mcp,
    ];

    /**
     * Prevent construction of a stateless policy.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }

    /**
     * Report whether a surface may open or resolve a Studio binding at all.
     *
     * @param   AuthenticatedSurface  $surface  Surface the execution context authenticated through.
     *
     * @return  bool  True for the administrator browser and the three machine surfaces.
     *
     * @since   2.0.0
     */
    public static function admits(AuthenticatedSurface $surface): bool
    {
        return $surface === AuthenticatedSurface::Administrator || self::isMachine($surface);
    }

    /**
     * Report whether a surface is one of the credentialed machine surfaces.
     *
     * @param   AuthenticatedSurface  $surface  Surface the execution context authenticated through.
     *
     * @return  bool  True for the REST, CLI and MCP surfaces.
     *
     * @since   2.0.0
     */
    public static function isMachine(AuthenticatedSurface $surface): bool
    {
        return in_array($surface, self::MACHINE_SURFACES, true);
    }

    /**
     * Derive the one-way binding a Studio context or session is tied to for this request.
     *
     * @param   ExecutionContext  $context  Fresh authenticated execution context.
     *
     * @return  ?string  Lowercase SHA-256 digest, or null when the context carries nothing to bind to: an
     *          administrator context without a browser session, a machine context without an App
     *          principal, or a surface that holds no Studio binding.
     *
     * @since   2.0.0
     */
    public static function digest(ExecutionContext $context): ?string
    {
        $surface = $context->surface();
        if ($surface === AuthenticatedSurface::Administrator) {
            $sessionId = $context->sessionId();

            return $sessionId === null ? null : hash('sha256', $sessionId);
        }
        if (!self::isMachine($surface)) {
            return null;
        }

        return AuthenticatedPrincipal::of($context)?->credentialFingerprint();
    }
}
