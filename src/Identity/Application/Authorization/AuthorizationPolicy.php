<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Authorization;

use Kumwe\Access\AuthorizationDecision;
use Kumwe\Access\Capability;
use Kumwe\App\Identity\Domain\CapabilityGrant;
use Kumwe\Access\GrantScope;
use Kumwe\App\Identity\Domain\User;

/**
 * Contract for one rule that may allow a capability, refuse it, ask for more assurance, or abstain.
 *
 * `AuthorizationService` puts each decision to the registered policies in turn, so a new rule is added
 * by registering another implementation rather than by editing the ones already there. Abstaining is
 * the normal outcome: an implementation answers only for the question it understands and returns null
 * — or a `not_applicable` decision — otherwise. Because a single denial settles the whole decision and
 * no allowance from any other policy can undo it, an implementation should deny only when it means to
 * veto every other policy, and abstain when it merely has nothing to say. A `step_up` verdict is not
 * permission either: it outranks every allowance and yields only to a denial.
 *
 * @since  2.0.0
 */
interface AuthorizationPolicy
{
    /**
     * Judge one capability request in one scope, or abstain from judging it.
     *
     * Return null when this policy does not apply. An explicit denial always
     * takes precedence over an allowance from another policy.
     *
     * @param   User                   $user        Actor the request is being judged for.
     * @param   Capability             $capability  Capability the actor is trying to exercise.
     * @param   GrantScope             $scope       Reach the capability is being exercised over.
     * @param   list<CapabilityGrant>  $grants      Role-derived grants supplied with the request for a policy
     *          to match against; empty when the caller offered none.
     *
     * @return  ?AuthorizationDecision  A four-state verdict naming the policy and reason that produced it, or
     *          null to abstain.
     *
     * @since   2.0.0
     */
    public function decide(
        User $user,
        Capability $capability,
        GrantScope $scope,
        array $grants,
    ): ?AuthorizationDecision;
}
