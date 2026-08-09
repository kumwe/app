<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authentication;

/**
 * Access-token verifier that returns the exact live delegation envelope as well as the principal.
 *
 * @since  2.0.0
 */
interface ScopedAccessTokenVerifier extends AccessTokenVerifier
{
    /**
     * Resolve a credential only while its site, membership, policy, audience and purpose remain current.
     *
     * @param   string  $token           Presented bearer secret.
     * @param   string  $audience        Exact delivery audience.
     * @param   string  $purpose         Exact delegated purpose.
     * @param   string  $siteIdentifier  Exact site being presented against.
     *
     * @return  ?VerifiedAccessToken  Live principal and immutable delegation scope, or null for every denial.
     *
     * @since   2.0.0
     */
    public function verifyScoped(
        string $token,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        string $siteIdentifier = 'default',
    ): ?VerifiedAccessToken;
}
