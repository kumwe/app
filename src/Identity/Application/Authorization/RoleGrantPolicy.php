<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Authorization;

use Kumwe\App\Identity\Domain\AuthorizationDecision;
use Kumwe\App\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\GrantScope;
use Kumwe\App\Identity\Domain\User;

/**
 * Turns the role-derived grants gathered for a decision into an allowance.
 *
 * This is the rule that makes assigning a role mean something. The `CapabilityGrant` values a user's
 * roles confer are handed to `AuthorizationService::decide()`, which passes them to every registered
 * policy; this one answers whether any of them reaches the capability and scope being requested. It
 * consults only the grants it is given and never reads storage, so it adds no I/O to a decision, and
 * it allows or abstains but never denies. That last part is deliberate: a denial here would veto every
 * other policy, whereas abstaining leaves an unmatched request to the service's deny-by-default
 * outcome.
 *
 * @since  2.0.0
 */
final readonly class RoleGrantPolicy implements AuthorizationPolicy
{
    /**
     * Allow the request as soon as one supplied grant covers it, and abstain when none does.
     *
     * Grants are scanned in the order they were supplied and the first match wins, so the allowance
     * always reads `role.grant` and never records which grant carried it.
     *
     * @param   User                                              $user        Actor whose roles the grants match.
     * @param   Capability                                        $capability  Capability the actor is exercising.
     * @param   GrantScope                                        $scope       Reach the capability is used over.
     * @param   list<\Kumwe\App\Identity\Domain\CapabilityGrant>  $grants      Role-derived grants to
     *          search; an empty list always abstains.
     *
     * @return  ?AuthorizationDecision  An allowance reasoned `role.grant`, or null when nothing matched.
     *
     * @since   2.0.0
     */
    public function decide(
        User $user,
        Capability $capability,
        GrantScope $scope,
        array $grants,
    ): ?AuthorizationDecision {
        foreach ($grants as $grant) {
            if ($grant->appliesTo($user, $capability, $scope)) {
                return AuthorizationDecision::allow('role.grant');
            }
        }

        return null;
    }
}
