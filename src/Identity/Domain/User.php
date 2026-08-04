<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

use DomainException;
use InvalidArgumentException;

final class User
{
    /** @var list<string> */
    private array $roles;

    /** @param array<mixed> $roles */
    private function __construct(
        private readonly string $id,
        private EmailAddress $email,
        private string $displayName,
        array $roles,
        private UserStatus $status,
        private int $version,
    ) {
        self::assertId($id);
        self::assertDisplayName($displayName);

        if ($version < 0) {
            throw new InvalidArgumentException('A user version cannot be negative.');
        }

        $this->roles = self::normalizeRoles($roles);
    }

    public static function register(string $id, EmailAddress $email, string $displayName): self
    {
        return new self(strtolower($id), $email, trim($displayName), [], UserStatus::Pending, 0);
    }

    /** @param array<mixed> $roles */
    public static function reconstitute(
        string $id,
        EmailAddress $email,
        string $displayName,
        array $roles,
        UserStatus $status,
        int $version,
    ): self {
        return new self(strtolower($id), $email, trim($displayName), $roles, $status, $version);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->roles;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function changeEmail(EmailAddress $email): void
    {
        if ($this->email->equals($email)) {
            return;
        }

        $this->email = $email;
        ++$this->version;
    }

    public function rename(string $displayName): void
    {
        $displayName = trim($displayName);
        self::assertDisplayName($displayName);

        if ($this->displayName === $displayName) {
            return;
        }

        $this->displayName = $displayName;
        ++$this->version;
    }

    public function activate(): void
    {
        if ($this->status === UserStatus::Disabled) {
            throw new DomainException('A disabled user cannot be reactivated.');
        }

        $this->transitionTo(UserStatus::Active);
    }

    public function suspend(): void
    {
        if ($this->status !== UserStatus::Active) {
            throw new DomainException('Only an active user can be suspended.');
        }

        $this->transitionTo(UserStatus::Suspended);
    }

    public function disable(): void
    {
        $this->transitionTo(UserStatus::Disabled);
    }

    public function assignRole(string $role): void
    {
        CapabilityGrant::assertRole($role);

        if ($this->hasRole($role)) {
            return;
        }

        $this->roles[] = $role;
        sort($this->roles, SORT_STRING);
        ++$this->version;
    }

    public function revokeRole(string $role): void
    {
        CapabilityGrant::assertRole($role);
        $index = array_search($role, $this->roles, true);

        if ($index === false) {
            return;
        }

        array_splice($this->roles, $index, 1);
        ++$this->version;
    }

    private function transitionTo(UserStatus $status): void
    {
        if ($this->status === $status) {
            return;
        }

        $this->status = $status;
        ++$this->version;
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $id) !== 1) {
            throw new InvalidArgumentException('A user ID must be a canonical UUID.');
        }
    }

    private static function assertDisplayName(string $displayName): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($displayName) : strlen($displayName);

        if ($displayName === '' || $length > 191 || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1) {
            throw new InvalidArgumentException('A display name must contain between 1 and 191 printable characters.');
        }
    }

    /**
     * @param array<mixed> $roles
     * @return list<string>
     */
    private static function normalizeRoles(array $roles): array
    {
        if (!array_is_list($roles)) {
            throw new InvalidArgumentException('User roles must be a list.');
        }

        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new InvalidArgumentException('Every user role must be a string.');
            }

            CapabilityGrant::assertRole($role);
        }

        /** @var list<string> $roles */
        $roles = array_values(array_unique($roles));
        sort($roles, SORT_STRING);

        return $roles;
    }
}
