<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Identity\Domain\GrantScope;
use Kumwe\CMS\Identity\Domain\UserStatus;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class AccessControlService
{
    public function __construct(
        private AccessControlRepository $repository,
        private PasswordHasher $passwords,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function users(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('user'));
        return $this->filterPaged($context, 'user', $this->repository->users(...));
    }

    /** @return list<array<string, mixed>> */
    public function roles(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('role'));
        return $this->filterPaged($context, 'role', $this->repository->roles(...));
    }

    /** @return list<array{code: string, description: string}> */
    public function capabilities(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('capability'));
        $rows = $this->filterPaged(
            $context,
            'capability',
            $this->repository->capabilities(...),
            'code',
        );

        return array_map(static function (array $row): array {
            $code = $row['code'] ?? null;
            $description = $row['description'] ?? null;
            if (!is_string($code) || !is_string($description)) {
                throw new RuntimeException('A capability record is invalid.');
            }

            return ['code' => $code, 'description' => $description];
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    public function tokens(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('api_token'));
        return $this->filterPaged($context, 'api_token', $this->repository->tokens(...));
    }

    public function createUser(
        ExecutionContext $context,
        string $email,
        string $displayName,
        string $password,
        UserStatus $status = UserStatus::Active,
    ): string {
        $this->authorize($context, AuthorizationResource::collection('user'));
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
            $context,
            $at,
        ): string {
            $this->repository->insertUser(
                $id,
                $email,
                $displayName,
                $status->value,
                $hash,
                $at,
            );
            $this->ownership->record(AuthorizationResource::item('user', $id), SiteContext::default());
            $this->audit($context->actorId(), 'user.create', 'user', $id, ['status' => $status->value]);

            return $id;
        });
    }

    public function updateUser(
        ExecutionContext $context,
        string $id,
        string $email,
        string $displayName,
        UserStatus $status,
        int $expectedVersion,
    ): void {
        $this->authorize($context, AuthorizationResource::item('user', $id));
        if ($id === $context->actorId() && !$status->canAuthenticate()) {
            throw new InvalidArgumentException('You cannot disable or suspend your own administrator account.');
        }

        $email = EmailAddress::fromString($email)->value();
        $displayName = $this->displayName($displayName);
        $at = $this->clock->now();
        $this->transactions->transactional(function () use (
            $context,
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
                $at,
            );
            $this->audit($context->actorId(), 'user.update', 'user', $id, ['status' => $status->value]);
        });
    }

    public function createRole(ExecutionContext $context, string $code, string $name): string
    {
        $this->authorize($context, AuthorizationResource::collection('role'));
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
        return $this->transactions->transactional(function () use ($context, $id, $code, $name, $at): string {
            $this->repository->insertRole($id, $code, $name, $at);
            $this->ownership->record(AuthorizationResource::item('role', $id), SiteContext::default());
            $this->audit($context->actorId(), 'role.create', 'role', $id, ['code' => $code]);

            return $id;
        });
    }

    public function assignRole(ExecutionContext $context, string $userId, string $roleId): void
    {
        $this->authorize($context, AuthorizationResource::item('user', $userId));
        $this->authorize($context, AuthorizationResource::item('role', $roleId));
        foreach ($this->repository->roleGrants($roleId) as $grant) {
            $this->authorization->assertCanDelegate(
                $context,
                Capability::fromString($grant['capability']),
                $this->scope($grant['scope_type'], $grant['scope_identifier']),
            );
        }
        $actorId = $context->actorId();
        $at = $this->clock->now();
        $this->transactions->transactional(function () use ($actorId, $userId, $roleId, $at): void {
            $this->repository->assignRole($userId, $roleId, $actorId, $at);
            $this->audit($actorId, 'role.assign', 'user', $userId, ['role_id' => $roleId]);
        });
    }

    public function revokeRole(ExecutionContext $context, string $userId, string $roleId): void
    {
        $this->authorize($context, AuthorizationResource::item('user', $userId));
        $this->authorize($context, AuthorizationResource::item('role', $roleId));
        $actorId = $context->actorId();
        if ($actorId === $userId && $this->repository->roleCode($roleId) === 'administrator') {
            throw new InvalidArgumentException('You cannot remove your own administrator role.');
        }
        $this->transactions->transactional(function () use ($actorId, $userId, $roleId): void {
            $this->repository->revokeRole($userId, $roleId);
            $this->audit($actorId, 'role.revoke', 'user', $userId, ['role_id' => $roleId]);
        });
    }

    public function grant(
        ExecutionContext $context,
        string $roleId,
        string $capability,
        string $scopeType = 'global',
        ?string $scopeIdentifier = null,
    ): string {
        $this->authorize($context, AuthorizationResource::item('role', $roleId));
        $actorId = $context->actorId();
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
        $scope = $this->scope($scopeType, $scopeIdentifier);
        $this->authorization->assertCanDelegate($context, Capability::fromString($capability), $scope);

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
                $at,
            );
            $this->ownership->record(AuthorizationResource::item('grant', $id), SiteContext::default());
            $this->audit($actorId, 'capability.grant', 'role', $roleId, [
                'capability' => $capability,
                'scope_type' => $scopeType,
                'scope_identifier' => $scopeIdentifier,
            ]);

            return $id;
        });
    }

    public function revokeGrant(ExecutionContext $context, string $grantId): void
    {
        $this->authorize($context, AuthorizationResource::item('grant', $grantId));
        $grant = $this->repository->grantRecord($grantId)
            ?? throw new InvalidArgumentException('The capability grant does not exist.');
        $this->authorize($context, AuthorizationResource::item('role', $grant['role_id']));
        $actorId = $context->actorId();
        $this->transactions->transactional(function () use ($actorId, $grantId): void {
            $this->repository->revokeGrant($grantId);
            $this->ownership->remove(
                AuthorizationResource::item('grant', $grantId),
                SiteContext::default(),
            );
            $this->audit($actorId, 'capability.revoke', 'grant', $grantId);
        });
    }

    public function revokeToken(ExecutionContext $context, string $tokenId): void
    {
        $this->authorize($context, AuthorizationResource::item('api_token', $tokenId));
        $actorId = $context->actorId();
        $at = $this->clock->now();
        $this->transactions->transactional(function () use ($actorId, $tokenId, $at): void {
            $this->repository->revokeToken($tokenId, $at);
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

    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('users.manage'),
            $resource,
        );
    }

    /**
     * @param callable(int, int): list<array<string, mixed>> $page
     * @return list<array<string, mixed>>
     */
    private function filterPaged(
        ExecutionContext $context,
        string $resourceType,
        callable $page,
        string $identifierField = 'id',
        int $limit = 100,
    ): array {
        $result = [];
        $offset = 0;
        $pageSize = 100;
        do {
            /** @var list<array<string, mixed>> $rows */
            $rows = $page($pageSize, $offset);
            foreach ($rows as $row) {
                $identifier = $row[$identifierField] ?? null;
                if (
                    is_string($identifier) && $this->authorization->decide(
                        $context,
                        Capability::fromString('users.manage'),
                        AuthorizationResource::item($resourceType, $identifier),
                    )->allowed
                ) {
                    $result[] = $row;
                    if (count($result) === $limit) {
                        return $result;
                    }
                }
            }
            $offset += count($rows);
        } while (count($rows) === $pageSize);

        return $result;
    }

    private function scope(string $type, ?string $identifier): GrantScope
    {
        return $type === 'global'
            ? GrantScope::global()
            : GrantScope::named($type, $identifier ?? '');
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
