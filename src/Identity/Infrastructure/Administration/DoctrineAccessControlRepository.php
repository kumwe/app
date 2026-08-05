<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Administration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

final readonly class DoctrineAccessControlRepository implements AccessControlRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function users(int $limit = 100, int $offset = 0): array
    {
        $this->assertPage($limit, $offset);
        $users = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, email, display_name, status, version, created_at, updated_at FROM %s '
            . 'ORDER BY display_name, email, id LIMIT %d OFFSET %d',
            $this->tables->quoted('users'),
            $limit,
            $offset,
        ));

        foreach ($users as &$user) {
            $user['roles'] = $this->database->fetchAllAssociative(sprintf(
                'SELECT r.id, r.code, r.name FROM %s r INNER JOIN %s ur ON ur.role_id = r.id '
                . 'WHERE ur.user_id = ? ORDER BY r.name',
                $this->tables->quoted('roles'),
                $this->tables->quoted('user_roles'),
            ), [$user['id']]);
        }
        unset($user);

        return $users;
    }

    public function roles(int $limit = 100, int $offset = 0): array
    {
        $this->assertPage($limit, $offset);
        $roles = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, code, name, created_at FROM %s ORDER BY name, id LIMIT %d OFFSET %d',
            $this->tables->quoted('roles'),
            $limit,
            $offset,
        ));

        foreach ($roles as &$role) {
            $role['grants'] = $this->database->fetchAllAssociative(sprintf(
                'SELECT id, capability_code AS capability, scope_type, scope_identifier FROM %s '
                . 'WHERE role_id = ? ORDER BY capability_code',
                $this->tables->quoted('role_capability_grants'),
            ), [$role['id']]);
        }
        unset($role);

        return $roles;
    }

    public function capabilities(int $limit = 100, int $offset = 0): array
    {
        $this->assertPage($limit, $offset);
        /** @var list<array{code: string, description: string}> $rows */
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT code, description FROM %s ORDER BY code LIMIT %d OFFSET %d',
            $this->tables->quoted('capabilities'),
            $limit,
            $offset,
        ));

        return $rows;
    }

    public function tokens(string $siteIdentifier, int $limit = 100, int $offset = 0): array
    {
        $this->assertPage($limit, $offset);
        $tokens = $this->database->fetchAllAssociative(sprintf(
            'SELECT t.id, t.name, t.capabilities, t.security_epoch, t.audience, t.purpose, t.site_identifier, '
            . 't.rotated_from, t.expires_at, t.revoked_at, t.revocation_reason, t.created_at, t.last_used_at, '
            . 'u.id AS subject_id, u.email AS subject_email, u.display_name AS subject_name '
            . 'FROM %s t INNER JOIN %s u ON u.id = t.subject_id '
            . 'WHERE t.site_identifier = ? ORDER BY t.created_at DESC, t.id DESC LIMIT %d OFFSET %d',
            $this->tables->quoted('api_tokens'),
            $this->tables->quoted('users'),
            $limit,
            $offset,
        ), [$siteIdentifier]);

        foreach ($tokens as &$token) {
            $capabilities = $token['capabilities'] ?? null;
            if (is_string($capabilities)) {
                try {
                    $capabilities = json_decode($capabilities, true, 32, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException('A stored token has invalid capabilities.', 0, $exception);
                }
            }
            if (!is_array($capabilities) || !array_is_list($capabilities)) {
                throw new InvalidArgumentException('A stored token has invalid capabilities.');
            }
            foreach ($capabilities as $capability) {
                if (!is_string($capability)) {
                    throw new InvalidArgumentException('A stored token has invalid capabilities.');
                }
            }
            $token['capabilities'] = $capabilities;
        }
        unset($token);

        return $tokens;
    }

    public function insertUser(
        string $id,
        string $email,
        string $displayName,
        string $status,
        string $passwordHash,
        DateTimeImmutable $at,
    ): void {
        $this->database->insert($this->tables->raw('users'), [
            'id' => $id,
            'email' => $email,
            'email_normalized' => $email,
            'display_name' => $displayName,
            'status' => $status,
            'version' => 1,
            'security_epoch' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->database->insert($this->tables->raw('password_credentials'), [
            'user_id' => $id,
            'password_hash' => $passwordHash,
            'changed_at' => $at,
        ], [
            'changed_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    public function updateUser(
        string $id,
        string $email,
        string $displayName,
        string $status,
        int $expectedVersion,
        DateTimeImmutable $at,
    ): void {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET email = ?, email_normalized = ?, display_name = ?, status = ?, '
            . 'version = version + 1, security_epoch = security_epoch + 1, updated_at = ? '
            . 'WHERE id = ? AND version = ?',
            $this->tables->quoted('users'),
        ), [$email, $email, $displayName, $status, $at, $id, $expectedVersion], [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::INTEGER,
        ]);
        $this->assertChanged($affected, 'user');
    }

    public function insertRole(string $id, string $code, string $name, DateTimeImmutable $at): void
    {
        $this->database->insert($this->tables->raw('roles'), [
            'id' => $id, 'code' => $code, 'name' => $name, 'created_at' => $at,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    public function assignRole(string $userId, string $roleId, string $actorId, DateTimeImmutable $at): void
    {
        $exists = $this->database->fetchOne(sprintf(
            'SELECT role_id FROM %s WHERE user_id = ? AND role_id = ?',
            $this->tables->quoted('user_roles'),
        ), [$userId, $roleId]);

        if ($exists === false) {
            $this->database->insert($this->tables->raw('user_roles'), [
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_at' => $at,
                'assigned_by' => $actorId,
            ], [
                'assigned_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->incrementSecurityEpoch($userId);
        }
    }

    public function revokeRole(string $userId, string $roleId): void
    {
        $this->assertChanged(
            $this->database->delete($this->tables->raw('user_roles'), ['user_id' => $userId, 'role_id' => $roleId]),
            'role assignment',
        );
        $this->incrementSecurityEpoch($userId);
    }

    public function grant(
        string $id,
        string $roleId,
        string $capability,
        string $scopeType,
        ?string $scopeIdentifier,
        string $actorId,
        DateTimeImmutable $at,
    ): void {
        $this->database->insert($this->tables->raw('role_capability_grants'), [
            'id' => $id,
            'role_id' => $roleId,
            'capability_code' => $capability,
            'scope_type' => $scopeType,
            'scope_identifier' => $scopeIdentifier,
            'granted_at' => $at,
            'granted_by' => $actorId,
        ], [
            'granted_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->incrementRoleMembersEpoch($roleId);
    }

    public function revokeGrant(string $grantId): void
    {
        $roleId = $this->database->fetchOne(sprintf(
            'SELECT role_id FROM %s WHERE id = ?',
            $this->tables->quoted('role_capability_grants'),
        ), [$grantId]);
        $this->assertChanged(
            $this->database->delete($this->tables->raw('role_capability_grants'), ['id' => $grantId]),
            'capability grant',
        );
        if (is_string($roleId)) {
            $this->incrementRoleMembersEpoch($roleId);
        }
    }

    public function revokeToken(string $tokenId, DateTimeImmutable $at): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            $this->tables->quoted('api_tokens'),
        ), [$at, $tokenId], [Types::DATETIME_IMMUTABLE, Types::STRING]);
        $this->assertChanged($affected, 'active API token');
    }

    public function userIdByEmail(string $normalizedEmail): ?string
    {
        $id = $this->database->fetchOne(sprintf(
            "SELECT id FROM %s WHERE email_normalized = ? AND status = 'active'",
            $this->tables->quoted('users'),
        ), [$normalizedEmail]);

        return is_string($id) ? $id : null;
    }

    public function tokenSite(string $tokenId): ?string
    {
        $site = $this->database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE id = ?',
            $this->tables->quoted('api_tokens'),
        ), [$tokenId]);
        return is_string($site) ? $site : null;
    }

    public function activeTokenForRotation(string $tokenId, bool $lock = false): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT t.subject_id, u.email, t.capabilities, t.site_identifier, t.audience, t.purpose FROM %s t '
            . 'INNER JOIN %s u ON u.id = t.subject_id WHERE t.id = ? AND t.revoked_at IS NULL '
            . 'AND t.security_epoch = u.security_epoch '
            . "AND t.expires_at > CURRENT_TIMESTAMP AND u.status = 'active'%s",
            $this->tables->quoted('api_tokens'),
            $this->tables->quoted('users'),
            $lock ? ' FOR UPDATE' : '',
        ), [$tokenId]);
        if ($row === false) {
            return null;
        }
        $capabilities = $row['capabilities'] ?? null;
        if (is_string($capabilities)) {
            $capabilities = json_decode($capabilities, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($capabilities) || !array_is_list($capabilities)) {
            throw new RuntimeException('The token capability inventory is invalid.');
        }
        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new RuntimeException('The token capability inventory is invalid.');
            }
        }
        $subjectId = $row['subject_id'] ?? null;
        $email = $row['email'] ?? null;
        $site = $row['site_identifier'] ?? null;
        $audience = $row['audience'] ?? null;
        $purpose = $row['purpose'] ?? null;
        if (
            !is_string($subjectId)
            || !is_string($email)
            || !is_string($site)
            || !is_string($audience)
            || !is_string($purpose)
        ) {
            throw new RuntimeException('The active token rotation record is invalid.');
        }
        return [
            'subject_id' => $subjectId,
            'email' => $email,
            'capabilities' => array_values($capabilities),
            'site_identifier' => $site,
            'audience' => $audience,
            'purpose' => $purpose,
        ];
    }

    public function revokeSubjectTokens(string $userId, DateTimeImmutable $at, string $reason): int
    {
        $this->incrementSecurityEpoch($userId);
        return (int) $this->database->executeStatement(sprintf(
            'UPDATE %s SET revoked_at = ?, revocation_reason = ? WHERE subject_id = ? AND revoked_at IS NULL',
            $this->tables->quoted('api_tokens'),
        ), [$at, $reason, $userId], [Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID]);
    }

    public function revokeSubjectTokensForSite(
        string $userId,
        string $siteIdentifier,
        DateTimeImmutable $at,
        string $reason,
    ): int {
        return (int) $this->database->executeStatement(sprintf(
            'UPDATE %s SET revoked_at = ?, revocation_reason = ? '
            . 'WHERE subject_id = ? AND site_identifier = ? AND revoked_at IS NULL',
            $this->tables->quoted('api_tokens'),
        ), [$at, $reason, $userId, $siteIdentifier], [
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::GUID,
            Types::STRING,
        ]);
    }

    public function lockUser(string $userId): void
    {
        $epoch = $this->database->fetchOne(sprintf(
            'SELECT security_epoch FROM %s WHERE id = ? FOR UPDATE',
            $this->tables->quoted('users'),
        ), [$userId]);
        if (!is_int($epoch) && (!is_string($epoch) || preg_match('/^[0-9]+$/D', $epoch) !== 1)) {
            throw new InvalidArgumentException('The user does not exist or could not be locked.');
        }
    }

    public function lockRole(string $roleId): void
    {
        $id = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ? FOR UPDATE',
            $this->tables->quoted('roles'),
        ), [$roleId]);
        if (!is_string($id)) {
            throw new InvalidArgumentException('The role does not exist or could not be locked.');
        }
    }

    public function roleCode(string $roleId): ?string
    {
        $code = $this->database->fetchOne(sprintf(
            'SELECT code FROM %s WHERE id = ?',
            $this->tables->quoted('roles'),
        ), [$roleId]);

        return is_string($code) ? $code : null;
    }

    public function roleGrants(string $roleId): array
    {
        /** @var list<array{capability: string, scope_type: string, scope_identifier: ?string}> $rows */
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT capability_code AS capability, scope_type, scope_identifier FROM %s '
            . 'WHERE role_id = ? ORDER BY capability_code, scope_type, scope_identifier',
            $this->tables->quoted('role_capability_grants'),
        ), [$roleId]);

        return $rows;
    }

    public function userGrants(string $userId): array
    {
        /** @var list<array{capability: string, scope_type: string, scope_identifier: ?string}> $rows */
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT DISTINCT g.capability_code AS capability, g.scope_type, g.scope_identifier '
            . 'FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id WHERE ur.user_id = ? '
            . 'ORDER BY g.capability_code, g.scope_type, g.scope_identifier',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('role_capability_grants'),
        ), [$userId]);

        return $rows;
    }

    public function grantRecord(string $grantId): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT role_id, capability_code AS capability, scope_type, scope_identifier FROM %s WHERE id = ?',
            $this->tables->quoted('role_capability_grants'),
        ), [$grantId]);

        if ($row === false) {
            return null;
        }

        foreach (['role_id', 'capability', 'scope_type'] as $field) {
            if (!is_string($row[$field] ?? null)) {
                throw new InvalidArgumentException('A stored capability grant is invalid.');
            }
        }
        $scopeIdentifier = $row['scope_identifier'] ?? null;
        if ($scopeIdentifier !== null && !is_string($scopeIdentifier)) {
            throw new InvalidArgumentException('A stored capability grant scope is invalid.');
        }

        return [
            'role_id' => $row['role_id'],
            'capability' => $row['capability'],
            'scope_type' => $row['scope_type'],
            'scope_identifier' => $scopeIdentifier,
        ];
    }

    private function assertChanged(int|string $affected, string $resource): void
    {
        if ((string) $affected !== '1') {
            throw new InvalidArgumentException(sprintf(
                'The %s does not exist or changed; reload and retry.',
                $resource,
            ));
        }
    }

    private function assertPage(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 500 || $offset < 0) {
            throw new InvalidArgumentException('The access-control page is invalid.');
        }
    }

    private function incrementSecurityEpoch(string $userId): void
    {
        $this->assertChanged($this->database->executeStatement(sprintf(
            'UPDATE %s SET security_epoch = security_epoch + 1 WHERE id = ?',
            $this->tables->quoted('users'),
        ), [$userId], [Types::GUID]), 'user');
    }

    private function incrementRoleMembersEpoch(string $roleId): void
    {
        $users = $this->database->fetchFirstColumn(sprintf(
            'SELECT user_id FROM %s WHERE role_id = ?',
            $this->tables->quoted('user_roles'),
        ), [$roleId]);
        foreach ($users as $userId) {
            if (is_string($userId)) {
                $this->incrementSecurityEpoch($userId);
            }
        }
    }
}
