<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

use InvalidArgumentException;

final readonly class CapabilityGrant
{
    public function __construct(
        private string $role,
        private Capability $capability,
        private GrantScope $scope,
    ) {
        self::assertRole($role);
    }

    public function role(): string
    {
        return $this->role;
    }

    public function capability(): Capability
    {
        return $this->capability;
    }

    public function scope(): GrantScope
    {
        return $this->scope;
    }

    public function appliesTo(User $user, Capability $capability, GrantScope $scope): bool
    {
        return $user->hasRole($this->role)
            && $this->capability->equals($capability)
            && $this->scope->covers($scope);
    }

    public static function assertRole(string $role): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $role) !== 1) {
            throw new InvalidArgumentException('A role must be a lowercase identifier between 2 and 64 characters.');
        }
    }
}
