<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authentication;

/**
 * Port that turns a presented bearer token into the principal it authenticates, or into nothing.
 *
 * Every surface that accepts a token resolves it through this one port — `BearerAuthenticationMiddleware`
 * for the REST API, `ConsoleAuthorizer` for management commands, the MCP handlers for tool calls — so
 * the rules about expiry, revocation, audience, purpose, site and account status live in a single
 * implementation instead of being restated per surface. The contract is deliberately null-returning:
 * an implementation reports every rejection identically rather than distinguishing a malformed token
 * from a revoked one, which keeps that difference out of the responses callers build from the result.
 *
 * @since  2.0.0
 */
interface AccessTokenVerifier
{
    /**
     * Resolve the principal a token authenticates for one audience, purpose and site.
     *
     * A token is accepted only when it was issued for exactly this audience, purpose and site, so a
     * credential minted for the REST API authenticates nobody on the console even while it remains
     * valid there. Implementations answer null for every failure — unknown, malformed, expired,
     * revoked, wrong surface, stale security epoch, disabled account or site — and never reveal which.
     *
     * @param   string  $token           Bearer credential exactly as the caller presented it.
     * @param   string  $audience        Surface the token must have been issued to, such as `kumwe-cli`.
     * @param   string  $purpose         Purpose the token must have been issued for, such as `management`.
     * @param   string  $siteIdentifier  Site the token is being presented against.
     *
     * @return  ?AuthenticatedPrincipal  The actor with its effective grants, or null when the token
     *          authenticates nobody on this surface.
     *
     * @since   2.0.0
     */
    public function verify(
        string $token,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        string $siteIdentifier = 'default',
    ): ?AuthenticatedPrincipal;
}
