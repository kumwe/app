<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

interface AccessControlRepository
{
    /** @return list<array<string, mixed>> */
    public function users(): array;

    /** @return list<array<string, mixed>> */
    public function roles(): array;

    /** @return list<array{code: string, description: string}> */
    public function capabilities(): array;

    /** @return list<array<string, mixed>> */
    public function tokens(): array;

    public function insertUser(
        string $id,
        string $email,
        string $displayName,
        string $status,
        string $passwordHash,
        string $at,
    ): void;

    public function updateUser(
        string $id,
        string $email,
        string $displayName,
        string $status,
        int $expectedVersion,
        string $at,
    ): void;

    public function insertRole(string $id, string $code, string $name, string $at): void;

    public function assignRole(string $userId, string $roleId, string $actorId, string $at): void;

    public function revokeRole(string $userId, string $roleId): void;

    public function grant(
        string $id,
        string $roleId,
        string $capability,
        string $scopeType,
        ?string $scopeIdentifier,
        string $actorId,
        string $at,
    ): void;

    public function revokeGrant(string $grantId): void;

    public function revokeToken(string $tokenId, string $at): void;

    public function roleCode(string $roleId): ?string;
}
