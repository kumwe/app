<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authorization;

use Kumwe\CMS\Identity\Domain\AuthorizationDecision;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;
use Kumwe\CMS\Identity\Domain\User;

final readonly class RoleGrantPolicy implements AuthorizationPolicy
{
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
