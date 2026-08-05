<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use DateTimeImmutable;

interface AccessControlRepository
{
    /** @return list<array<string, mixed>> */
    public function users(int $limit = 100, int $offset = 0): array;

    /** @return list<array<string, mixed>> */
    public function roles(int $limit = 100, int $offset = 0): array;

    /** @return list<array{code: string, description: string}> */
    public function capabilities(int $limit = 100, int $offset = 0): array;

    /** @return list<array<string, mixed>> */
    public function tokens(int $limit = 100, int $offset = 0): array;

    public function insertUser(
        string $id,
        string $email,
        string $displayName,
        string $status,
        string $passwordHash,
        DateTimeImmutable $at,
    ): void;

    public function updateUser(
        string $id,
        string $email,
        string $displayName,
        string $status,
        int $expectedVersion,
        DateTimeImmutable $at,
    ): void;

    public function insertRole(string $id, string $code, string $name, DateTimeImmutable $at): void;

    public function assignRole(string $userId, string $roleId, string $actorId, DateTimeImmutable $at): void;

    public function revokeRole(string $userId, string $roleId): void;

    public function grant(
        string $id,
        string $roleId,
        string $capability,
        string $scopeType,
        ?string $scopeIdentifier,
        string $actorId,
        DateTimeImmutable $at,
    ): void;

    public function revokeGrant(string $grantId): void;

    public function revokeToken(string $tokenId, DateTimeImmutable $at): void;

    public function userIdByEmail(string $normalizedEmail): ?string;

    public function roleCode(string $roleId): ?string;

    /** @return list<array{capability: string, scope_type: string, scope_identifier: ?string}> */
    public function roleGrants(string $roleId): array;

    /** @return list<array{capability: string, scope_type: string, scope_identifier: ?string}> */
    public function userGrants(string $userId): array;

    /**
     * @return array{role_id: string, capability: string, scope_type: string, scope_identifier: ?string}|null
     */
    public function grantRecord(string $grantId): ?array;
}
