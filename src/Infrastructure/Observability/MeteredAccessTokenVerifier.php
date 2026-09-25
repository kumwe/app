<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authentication\ScopedAccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\VerifiedAccessToken;

/**
 * Counts every bearer token that authenticates nobody as a `token_rejected` security event.
 *
 * The REST API, the console and the MCP handlers all resolve tokens through this one port, and the port
 * deliberately answers null for every kind of rejection. The decorator keeps that property: it counts the
 * null without learning or publishing why, and it returns exactly what the wrapped verifier returned.
 *
 * @since  2.0.0
 */
final readonly class MeteredAccessTokenVerifier implements ScopedAccessTokenVerifier
{
    /**
     * Bind the decorator to the verifier it wraps and the counter it increments.
     *
     * @param  ScopedAccessTokenVerifier  $inner    Verifier that decides every token.
     * @param  MetricRecorder             $metrics  Recorder of `kumwe_security_events_total`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ScopedAccessTokenVerifier $inner,
        private MetricRecorder $metrics,
    ) {
    }

    /**
     * Resolve a principal through the wrapped verifier, counting a rejection.
     *
     * @param   string  $token           Bearer credential exactly as the caller presented it.
     * @param   string  $audience        Surface the token must have been issued to.
     * @param   string  $purpose         Purpose the token must have been issued for.
     * @param   string  $siteIdentifier  Site the token is being presented against.
     *
     * @return  ?AuthenticatedPrincipal  The actor, or null when the token authenticates nobody here.
     *
     * @since   2.0.0
     */
    public function verify(
        string $token,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        string $siteIdentifier = 'default',
    ): ?AuthenticatedPrincipal {
        $principal = $this->inner->verify($token, $audience, $purpose, $siteIdentifier);
        if ($principal === null) {
            $this->metrics->increment(MetricCatalog::SECURITY_EVENTS, ['event' => 'token_rejected']);
        }

        return $principal;
    }

    /**
     * Resolve a principal and its delegation scope through the wrapped verifier, counting a rejection.
     *
     * @param   string  $token           Presented bearer secret.
     * @param   string  $audience        Exact delivery audience.
     * @param   string  $purpose         Exact delegated purpose.
     * @param   string  $siteIdentifier  Exact site being presented against.
     *
     * @return  ?VerifiedAccessToken  Live principal and scope, or null for every denial.
     *
     * @since   2.0.0
     */
    public function verifyScoped(
        string $token,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        string $siteIdentifier = 'default',
    ): ?VerifiedAccessToken {
        $verified = $this->inner->verifyScoped($token, $audience, $purpose, $siteIdentifier);
        if ($verified === null) {
            $this->metrics->increment(MetricCatalog::SECURITY_EVENTS, ['event' => 'token_rejected']);
        }

        return $verified;
    }
}
