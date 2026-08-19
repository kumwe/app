<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Authentication;

use Kumwe\App\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\GrantScope;

/**
 * One capability paired with the reach it was granted over: the unit of a principal's authority.
 *
 * `AuthenticatedPrincipal` carries a list of these rather than bare capability names, because the same
 * capability may be held globally by one actor and only over a single named resource by another.
 * `DenyByDefaultAuthorizationGateway` asks a principal whether any of its grants covers the scopes a
 * request targets, so keeping the two halves together is exactly what stops a grant over one resource
 * from being read as authority over every resource of that type.
 *
 * @since  2.0.0
 */
final readonly class PrincipalGrant
{
    /**
     * Pair a capability with the reach it is granted over.
     *
     * @param  Capability  $capability  Capability being conferred.
     * @param  GrantScope  $scope       Reach of the grant; `GrantScope::global()` for an unrestricted one.
     *
     * @since  2.0.0
     */
    public function __construct(private Capability $capability, private GrantScope $scope)
    {
    }

    /**
     * The capability half of the grant, compared against the capability a request is exercising.
     *
     * @return  Capability
     *
     * @since   2.0.0
     */
    public function capability(): Capability
    {
        return $this->capability;
    }

    /**
     * The reach half of the grant, tested with `GrantScope::covers()` against the scopes a request targets.
     *
     * @return  GrantScope
     *
     * @since   2.0.0
     */
    public function scope(): GrantScope
    {
        return $this->scope;
    }
}
