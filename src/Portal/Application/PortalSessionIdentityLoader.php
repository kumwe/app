<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Application;

/**
 * Live identity and membership loader used on every portal session resolution and rotation.
 *
 * @since  2.0.0
 */
interface PortalSessionIdentityLoader
{
    /**
     * Rebuild the actor and prove its stored portal selection is still active.
     *
     * @param   string   $subjectId          User UUID stored with the session.
     * @param   string   $siteIdentifier     Stored server-resolved site.
     * @param   ?string  $organizationIdentifier Stored organization selection, or null.
     * @param   ?string  $membershipId       Stored membership UUID, or null for site-only portal access.
     * @param   ?string  $workspaceIdentifier Stored workspace selection, or null.
     * @param   string   $sessionId          Stored session UUID used as principal credential provenance.
     *
     * @return  ?PortalSessionIdentity  Live identity or null after any user, site, membership, or policy invalidation.
     *
     * @since   2.0.0
     */
    public function load(
        string $subjectId,
        string $siteIdentifier,
        ?string $organizationIdentifier,
        ?string $membershipId,
        ?string $workspaceIdentifier,
        string $sessionId,
    ): ?PortalSessionIdentity;
}
