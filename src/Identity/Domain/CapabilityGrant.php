<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Domain;

use InvalidArgumentException;

/**
 * A role's permission to exercise one capability over one scope.
 *
 * Roles are what a user is assigned; a grant is what a role confers, and this is the unit the identity
 * model stores and reasons about. Keeping the scope beside the capability is what stops authority over
 * a single site from being read as authority over the installation, and `appliesTo()` is where the
 * three questions a decision asks — does the actor hold the role, is this the capability, does the
 * grant's reach cover the request — are answered as one. `RoleGrantPolicy` walks a list of these to
 * reach its verdict, and `User` borrows `assertRole()` so an assignable role and a grantable role are
 * always spelled the same way.
 *
 * @since  2.0.0
 */
final readonly class CapabilityGrant
{
    /**
     * Bind a role to the capability and the reach it confers.
     *
     * @param   string      $role        Role that holds the grant, validated on the way in.
     * @param   Capability  $capability  Capability the role may exercise.
     * @param   GrantScope  $scope       Reach it is conferred over; `GrantScope::global()` for an
     *          unrestricted grant.
     *
     * @throws  InvalidArgumentException  When the role is not a lowercase identifier of 2 to 64
     *          characters.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $role,
        private Capability $capability,
        private GrantScope $scope,
    ) {
        self::assertRole($role);
    }

    /**
     * The role this grant hangs off, matched against the roles a user is assigned.
     *
     * @return  string  Lowercase role identifier.
     *
     * @since   2.0.0
     */
    public function role(): string
    {
        return $this->role;
    }

    /**
     * The capability the role may exercise under this grant.
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
     * The reach the capability is conferred over, tested with `GrantScope::covers()`.
     *
     * @return  GrantScope
     *
     * @since   2.0.0
     */
    public function scope(): GrantScope
    {
        return $this->scope;
    }

    /**
     * Whether this grant answers the request being judged.
     *
     * All three halves must line up: the actor holds the role, the capability is the same one, and this
     * grant's scope covers the requested scope — which a global grant always does. The scope test runs
     * one way only, so a grant over one resource never satisfies a request against another.
     *
     * @param   User        $user        Actor whose assigned roles are checked.
     * @param   Capability  $capability  Capability being exercised.
     * @param   GrantScope  $scope       Reach the capability is being exercised over.
     *
     * @return  bool  True when the grant covers the request in full.
     *
     * @since   2.0.0
     */
    public function appliesTo(User $user, Capability $capability, GrantScope $scope): bool
    {
        return $user->hasRole($this->role)
            && $this->capability->equals($capability)
            && $this->scope->covers($scope);
    }

    /**
     * Validate a role identifier before it is granted against or assigned to a user.
     *
     * Exposed as a static so `User` can enforce exactly the grammar a grant enforces: a role that could
     * never appear on a grant can never be assigned either, which keeps the two sides of a role lookup
     * from silently drifting apart.
     *
     * @param   string  $role  Candidate role identifier.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the role is not a lowercase identifier of 2 to 64
     *          characters beginning with a letter.
     *
     * @since   2.0.0
     */
    public static function assertRole(string $role): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $role) !== 1) {
            throw new InvalidArgumentException('A role must be a lowercase identifier between 2 and 64 characters.');
        }
    }
}
