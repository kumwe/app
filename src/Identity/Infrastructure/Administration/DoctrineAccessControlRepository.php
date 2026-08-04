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

final readonly class DoctrineAccessControlRepository implements AccessControlRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function users(): array
    {
        $users = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, email, display_name, status, version, created_at, updated_at FROM %s '
            . 'ORDER BY display_name, email',
            $this->tables->quoted('users'),
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

    public function roles(): array
    {
        $roles = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, code, name, created_at FROM %s ORDER BY name',
            $this->tables->quoted('roles'),
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

    public function capabilities(): array
    {
        /** @var list<array{code: string, description: string}> $rows */
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT code, description FROM %s ORDER BY code',
            $this->tables->quoted('capabilities'),
        ));

        return $rows;
    }

    public function tokens(): array
    {
        $tokens = $this->database->fetchAllAssociative(sprintf(
            'SELECT t.id, t.name, t.capabilities, t.expires_at, t.revoked_at, t.created_at, t.last_used_at, '
            . 'u.id AS subject_id, u.email AS subject_email, u.display_name AS subject_name '
            . 'FROM %s t INNER JOIN %s u ON u.id = t.subject_id ORDER BY t.created_at DESC',
            $this->tables->quoted('api_tokens'),
            $this->tables->quoted('users'),
        ));

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
            . 'version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
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
        }
    }

    public function revokeRole(string $userId, string $roleId): void
    {
        $this->assertChanged(
            $this->database->delete($this->tables->raw('user_roles'), ['user_id' => $userId, 'role_id' => $roleId]),
            'role assignment',
        );
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
    }

    public function revokeGrant(string $grantId): void
    {
        $this->assertChanged(
            $this->database->delete($this->tables->raw('role_capability_grants'), ['id' => $grantId]),
            'capability grant',
        );
    }

    public function revokeToken(string $tokenId, DateTimeImmutable $at): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            $this->tables->quoted('api_tokens'),
        ), [$at, $tokenId], [Types::DATETIME_IMMUTABLE, Types::STRING]);
        $this->assertChanged($affected, 'active API token');
    }

    public function roleCode(string $roleId): ?string
    {
        $code = $this->database->fetchOne(sprintf(
            'SELECT code FROM %s WHERE id = ?',
            $this->tables->quoted('roles'),
        ), [$roleId]);

        return is_string($code) ? $code : null;
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
}
