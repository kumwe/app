<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authorization;

use Kumwe\CMS\Identity\Domain\AuthorizationDecision;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\CapabilityGrant;
use Kumwe\CMS\Identity\Domain\GrantScope;
use Kumwe\CMS\Identity\Domain\User;

interface AuthorizationPolicy
{
    /**
     * Return null when this policy does not apply. An explicit denial always
     * takes precedence over an allowance from another policy.
     *
     * @param list<CapabilityGrant> $grants
     */
    public function decide(
        User $user,
        Capability $capability,
        GrantScope $scope,
        array $grants,
    ): ?AuthorizationDecision;
}
