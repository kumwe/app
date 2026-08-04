<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use InvalidArgumentException;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Identity\Domain\UserStatus;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

final readonly class AccessControlService
{
    public function __construct(
        private AccessControlRepository $repository,
        private PasswordHasher $passwords,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function users(): array
    {
        return $this->repository->users();
    }

    /** @return list<array<string, mixed>> */
    public function roles(): array
    {
        return $this->repository->roles();
    }

    /** @return list<array{code: string, description: string}> */
    public function capabilities(): array
    {
        return $this->repository->capabilities();
    }

    /** @return list<array<string, mixed>> */
    public function tokens(): array
    {
        return $this->repository->tokens();
    }

    public function createUser(
        string $actorId,
        string $email,
        string $displayName,
        string $password,
        UserStatus $status = UserStatus::Active,
    ): string {
        $id = Uuid::uuid7()->toString();
        $email = EmailAddress::fromString($email)->value();
        $displayName = $this->displayName($displayName);
        $hash = $this->passwords->hash($password);
        $at = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $id,
            $email,
            $displayName,
            $status,
            $hash,
            $actorId,
            $at,
        ): string {
            $this->repository->insertUser(
                $id,
                $email,
                $displayName,
                $status->value,
                $hash,
                $at->format('Y-m-d H:i:s.uP'),
            );
            $this->audit($actorId, 'user.create', 'user', $id, ['status' => $status->value]);

            return $id;
        });
    }

    public function updateUser(
        string $actorId,
        string $id,
        string $email,
        string $displayName,
        UserStatus $status,
        int $expectedVersion,
    ): void {
        if ($id === $actorId && !$status->canAuthenticate()) {
            throw new InvalidArgumentException('You cannot disable or suspend your own administrator account.');
        }

        $email = EmailAddress::fromString($email)->value();
        $displayName = $this->displayName($displayName);
        $at = $this->clock->now();
        $this->transactions->transactional(function () use (
            $actorId,
            $id,
            $email,
            $displayName,
            $status,
            $expectedVersion,
            $at,
        ): void {
            $this->repository->updateUser(
                $id,
                $email,
                $displayName,
                $status->value,
                $expectedVersion,
                $at->format('Y-m-d H:i:s.uP'),
            );
            $this->audit($actorId, 'user.update', 'user', $id, ['status' => $status->value]);
        });
    }

    public function createRole(string $actorId, string $code, string $name): string
    {
        $code = strtolower(trim($code));
        $name = trim($name);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('A role code must be a stable lowercase identifier.');
        }
        if ($name === '' || mb_strlen($name) > 191) {
            throw new InvalidArgumentException('A role name must contain 1 to 191 characters.');
        }

        $id = Uuid::uuid7()->toString();
        $at = $this->clock->now();
        return $this->transactions->transactional(function () use ($actorId, $id, $code, $name, $at): string {
            $this->repository->insertRole($id, $code, $name, $at->format('Y-m-d H:i:s.uP'));
            $this->audit($actorId, 'role.create', 'role', $id, ['code' => $code]);

            return $id;
        });
    }

    public function assignRole(string $actorId, string $userId, string $roleId): void
    {
        $at = $this->clock->now();
        $this->transactions->transactional(function () use ($actorId, $userId, $roleId, $at): void {
            $this->repository->assignRole($userId, $roleId, $actorId, $at->format('Y-m-d H:i:s.uP'));
            $this->audit($actorId, 'role.assign', 'user', $userId, ['role_id' => $roleId]);
        });
    }

    public function revokeRole(string $actorId, string $userId, string $roleId): void
    {
        if ($actorId === $userId && $this->repository->roleCode($roleId) === 'administrator') {
            throw new InvalidArgumentException('You cannot remove your own administrator role.');
        }
        $this->transactions->transactional(function () use ($actorId, $userId, $roleId): void {
            $this->repository->revokeRole($userId, $roleId);
            $this->audit($actorId, 'role.revoke', 'user', $userId, ['role_id' => $roleId]);
        });
    }

    public function grant(
        string $actorId,
        string $roleId,
        string $capability,
        string $scopeType = 'global',
        ?string $scopeIdentifier = null,
    ): string {
        $capability = Capability::fromString($capability)->value();
        $scopeType = strtolower(trim($scopeType));
        if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $scopeType) !== 1) {
            throw new InvalidArgumentException('The grant scope type is invalid.');
        }
        $scopeIdentifier = $scopeIdentifier === null ? null : trim($scopeIdentifier);
        if (($scopeType === 'global') !== ($scopeIdentifier === null)) {
            throw new InvalidArgumentException(
                'Global grants cannot have a scope identifier; scoped grants require one.',
            );
        }

        $id = Uuid::uuid7()->toString();
        $at = $this->clock->now();
        return $this->transactions->transactional(function () use (
            $id,
            $roleId,
            $capability,
            $scopeType,
            $scopeIdentifier,
            $actorId,
            $at,
        ): string {
            $this->repository->grant(
                $id,
                $roleId,
                $capability,
                $scopeType,
                $scopeIdentifier,
                $actorId,
                $at->format('Y-m-d H:i:s.uP'),
            );
            $this->audit($actorId, 'capability.grant', 'role', $roleId, [
                'capability' => $capability,
                'scope_type' => $scopeType,
                'scope_identifier' => $scopeIdentifier,
            ]);

            return $id;
        });
    }

    public function revokeGrant(string $actorId, string $grantId): void
    {
        $this->transactions->transactional(function () use ($actorId, $grantId): void {
            $this->repository->revokeGrant($grantId);
            $this->audit($actorId, 'capability.revoke', 'grant', $grantId);
        });
    }

    public function revokeToken(string $actorId, string $tokenId): void
    {
        $at = $this->clock->now();
        $this->transactions->transactional(function () use ($actorId, $tokenId, $at): void {
            $this->repository->revokeToken($tokenId, $at->format('Y-m-d H:i:s.uP'));
            $this->audit($actorId, 'token.revoke', 'api_token', $tokenId);
        });
    }

    private function displayName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 191) {
            throw new InvalidArgumentException('A user display name must contain 1 to 191 characters.');
        }

        return $name;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $actorId, string $action, string $type, string $id, array $metadata = []): void
    {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $actorId,
            $action,
            $type,
            $id,
            'success',
            $metadata,
        ));
    }
}
