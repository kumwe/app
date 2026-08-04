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

    /** @param iterable<mixed> $policies */
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

    /** @param array<mixed> $grants */
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
