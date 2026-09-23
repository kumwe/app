<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Authorization;

use InvalidArgumentException;
use Kumwe\Access\AuthorizationDecision;
use Kumwe\Access\Capability;
use Kumwe\Access\DecisionState;
use Kumwe\App\Identity\Domain\CapabilityGrant;
use Kumwe\Access\GrantScope;
use Kumwe\App\Identity\Domain\User;

/**
 * Resolves the registered authorization policies into a single verdict, denying unless one allows.
 *
 * The combination rule is fixed here rather than in the policies, so that adding a rule cannot change
 * how the existing ones interact: a user who cannot authenticate is refused before any policy runs, a
 * denial from any policy settles the outcome whatever another policy said, a step-up demand outranks
 * every allowance, an abstention is ignored, and a request that every policy abstains on — including
 * the case of no policies at all — is denied. Nothing is allowed by default. Every verdict is the
 * canonical four-state decision carrying a stable policy code and reason token, so an audit trail
 * records not just that a request was refused but which rule refused it.
 *
 * @since  2.0.0
 */
final readonly class AuthorizationService
{
    /**
     * Stable policy code for the verdicts this service reaches itself rather than through a policy.
     *
     * @var    string
     * @since  2.0.0
     */
    private const POLICY = 'core.identity-authorization.v1';

    /**
     * The policies consulted for every decision, in the order they were registered.
     *
     * @var    list<AuthorizationPolicy>
     * @since  2.0.0
     */
    private array $policies;

    /**
     * Collect the policies this service consults, rejecting anything that is not one.
     *
     * The parameter is `iterable` so a container's lazily built service tag can be passed straight in.
     * It is drained once, here, which means a generator is consumed and type-checked at construction
     * rather than part-way through the first decision.
     *
     * @param   iterable<mixed>  $policies  Policies to register, each of which must be an `AuthorizationPolicy`.
     *
     * @throws  InvalidArgumentException  When an entry does not implement `AuthorizationPolicy`.
     *
     * @since   2.0.0
     */
    public function __construct(iterable $policies)
    {
        $normalized = [];

        foreach ($policies as $policy) {
            if (!($policy instanceof AuthorizationPolicy)) {
                throw new InvalidArgumentException('Authorization policies must implement AuthorizationPolicy.');
            }

            $normalized[] = $policy;
        }

        $this->policies = $normalized;
    }

    /**
     * Reach a single verdict on whether a user may exercise a capability over a scope.
     *
     * The order of resolution is fixed and observable. A user whose status forbids authentication is
     * refused as `user.inactive` before any policy is consulted, so a suspended account cannot be
     * rescued by a permissive rule. Policies then run in registration order; the first denial returns
     * immediately and leaves the remaining policies unconsulted, while an allowance or a step-up demand
     * is held back until every policy has had its say, so a later denial still wins. A step-up demand
     * is returned ahead of any allowance, because it is not permission. A null answer or a
     * `not_applicable` decision is an abstention, and a request no policy speaks for is denied as
     * `policy.no_allowance`.
     *
     * @param   User          $user        Actor the request is being judged for.
     * @param   Capability    $capability  Capability the actor is trying to exercise.
     * @param   GrantScope    $scope       Reach the capability is being exercised over.
     * @param   array<mixed>  $grants      Role-derived `CapabilityGrant` values, as a list, passed on to every
     *          policy; empty when the caller has none to offer.
     *
     * @return  AuthorizationDecision  The verdict, carrying the reason that settled it: `user.inactive`,
     *          `policy.no_allowance`, or whichever policy and reason the deciding policy gave.
     *
     * @throws  InvalidArgumentException  When the grants are not a list, or an entry is not a CapabilityGrant.
     *
     * @since   2.0.0
     */
    public function decide(
        User $user,
        Capability $capability,
        GrantScope $scope,
        array $grants = [],
    ): AuthorizationDecision {
        if (!array_is_list($grants)) {
            throw new InvalidArgumentException('Capability grants must be a list.');
        }

        foreach ($grants as $grant) {
            if (!($grant instanceof CapabilityGrant)) {
                throw new InvalidArgumentException('Every capability grant must be a CapabilityGrant.');
            }
        }

        /** @var list<CapabilityGrant> $grants */
        if (!$user->canAuthenticate()) {
            return new AuthorizationDecision(DecisionState::Deny, self::POLICY, 'user.inactive');
        }

        $allowance = null;
        $stepUp = null;

        foreach ($this->policies as $policy) {
            $decision = $policy->decide($user, $capability, $scope, $grants);

            if ($decision === null || $decision->state === DecisionState::NotApplicable) {
                continue;
            }

            if ($decision->state === DecisionState::Deny) {
                return $decision;
            }

            if ($decision->state === DecisionState::StepUp) {
                $stepUp ??= $decision;

                continue;
            }

            $allowance ??= $decision;
        }

        return $stepUp
            ?? $allowance
            ?? new AuthorizationDecision(DecisionState::Deny, self::POLICY, 'policy.no_allowance');
    }
}
