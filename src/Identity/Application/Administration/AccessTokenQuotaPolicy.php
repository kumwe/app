<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

/**
 * Ceiling on how many API tokens one subject may hold live for a single site, audience and purpose.
 *
 * Issuance counts the subject's unrevoked, unexpired tokens inside the transaction that would write
 * the new one and hands the count here instead of judging it, so a deployment can tighten or relax
 * the limit — or vary it by purpose — without touching `DoctrineAdministratorIdentityGateway`. The
 * policy's only lever is refusal: returning admits the token, raising aborts the whole issuing
 * transaction. `FixedAccessTokenQuotaPolicy` is the shipped implementation and applies one number to
 * every scope.
 *
 * @since  2.0.0
 */
interface AccessTokenQuotaPolicy
{
    /**
     * Decide whether one more live token may be minted for this subject and scope.
     *
     * The count is taken under the subject's row lock and excludes the token being replaced during a
     * rotation, so rotating at the ceiling is not mistaken for exceeding it.
     *
     * @param   string  $subjectId       UUID of the user the new token would authenticate as.
     * @param   string  $siteIdentifier  Site the new token would be confined to.
     * @param   string  $audience        Audience the new token would be accepted for, such as `kumwe-http`.
     * @param   string  $purpose         Purpose the new token would be issued under, such as `api`.
     * @param   int     $activeTokens    Live tokens already counted for exactly this subject and scope.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the scope already holds as many live tokens as it may.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        string $subjectId,
        string $siteIdentifier,
        string $audience,
        string $purpose,
        int $activeTokens,
    ): void;
}
