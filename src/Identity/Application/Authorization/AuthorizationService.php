<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authorization;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\AuthorizationDecision;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\CapabilityGrant;
use Kumwe\CMS\Identity\Domain\GrantScope;
use Kumwe\CMS\Identity\Domain\User;

/**
 * Resolves the registered authorization policies into a single verdict, denying unless one allows.
 *
 * The combination rule is fixed here rather than in the policies, so that adding a rule cannot change
 * how the existing ones interact: a user who cannot authenticate is refused before any policy runs, a
 * denial from any policy settles the outcome whatever another policy said, and a request that every
 * policy abstains on — including the case of no policies at all — is denied. Nothing is allowed by
 * default. Every verdict carries a stable reason token, so an audit trail records not just that a
 * request was refused but which rule refused it.
 *
 * @since  2.0.0
 */
final readonly class AuthorizationService
{
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
     * immediately and leaves the remaining policies unconsulted, while an allowance is held back until
     * every policy has had its say, so a later denial still wins. A request no policy speaks for is
     * denied as `policy.no_allowance`.
     *
     * @param   User          $user        Actor the request is being judged for.
     * @param   Capability    $capability  Capability the actor is trying to exercise.
     * @param   GrantScope    $scope       Reach the capability is being exercised over.
     * @param   array<mixed>  $grants      Role-derived `CapabilityGrant` values, as a list, passed on to every
     *          policy; empty when the caller has none to offer.
     *
     * @return  AuthorizationDecision  The verdict, carrying the reason that settled it: `user.inactive`,
     *          `policy.no_allowance`, or whichever reason the deciding policy gave.
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
            return AuthorizationDecision::deny('user.inactive');
        }

        $allowance = null;

        foreach ($this->policies as $policy) {
            $decision = $policy->decide($user, $capability, $scope, $grants);

            if ($decision === null) {
                continue;
            }

            if ($decision->isDenied()) {
                return $decision;
            }

            $allowance ??= $decision;
        }

        return $allowance ?? AuthorizationDecision::deny('policy.no_allowance');
    }
}
