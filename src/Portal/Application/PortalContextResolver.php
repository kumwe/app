<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Portal\Application\PortalContext;

/**
 * Membership boundary that converts an optional workspace hint into a live server-owned portal scope.
 *
 * @since  2.0.0
 */
interface PortalContextResolver
{
    /**
     * Select a site and membership the actor currently holds.
     *
     * The hint may choose among live memberships but can never establish an organization or workspace;
     * an unknown, disabled, expired, or stale selection must return null.
     *
     * @param   AuthenticatedPrincipal  $principal      Live authenticated actor.
     * @param   ?string                 $workspaceHint  Optional bounded UI selection hint.
     *
     * @return  ?PortalContext  Server-issued context or null when no active portal membership permits it.
     *
     * @since   2.0.0
     */
    public function resolve(AuthenticatedPrincipal $principal, ?string $workspaceHint): ?PortalContext;
}
