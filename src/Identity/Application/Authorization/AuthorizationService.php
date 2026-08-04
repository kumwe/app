<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authorization;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\AuthorizationDecision;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\CapabilityGrant;
use Kumwe\CMS\Identity\Domain\GrantScope;
use Kumwe\CMS\Identity\Domain\User;

final readonly class AuthorizationService
{
    /** @var list<AuthorizationPolicy> */
    private array $policies;

    /** @param iterable<AuthorizationPolicy> $policies */
    public function __construct(iterable $policies)
    {
        $normalized = [];

        foreach ($policies as $policy) {
            if (!$policy instanceof AuthorizationPolicy) {
                throw new InvalidArgumentException('Every authorization policy must implement AuthorizationPolicy.');
            }

            $normalized[] = $policy;
        }

        $this->policies = $normalized;
    }

    /** @param list<CapabilityGrant> $grants */
    public function decide(
        User $user,
        Capability $capability,
        GrantScope $scope,
        array $grants = [],
    ): AuthorizationDecision {
        if (!$user->canAuthenticate()) {
            return AuthorizationDecision::deny('user.inactive');
        }

        foreach ($grants as $grant) {
            if (!$grant instanceof CapabilityGrant) {
                throw new InvalidArgumentException('Every grant must be a CapabilityGrant.');
            }
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
