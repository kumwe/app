<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Administration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\CMS\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\CMS\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\CMS\Identity\Application\Administration\AccessTokenQuotaPolicy;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenContext;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class DoctrineAdministratorIdentityGateway implements AdministratorIdentityGateway
{
    private const DEFAULT_TOKEN_LIFETIME = '+30 days';
    private const MAXIMUM_TOKEN_LIFETIME = '+90 days';
    /** @var list<string> */
    private const ADMINISTRATOR_CAPABILITIES = [
        'administrator.access', 'automation.manage', 'content.archive', 'content.create', 'content.delete',
        'content.publish', 'content.read', 'content.restore', 'content.review', 'content.submit',
        'content.unpublish', 'content.update', 'extensions.manage', 'navigation.manage', 'settings.manage',
        'themes.administrator.manage', 'themes.site.manage', 'users.manage',
    ];

    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private PasswordHasher $passwords,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private AuthenticationRateLimiter $rateLimiter,
        private AuditRecorder $audit,
        private AccessTokenQuotaPolicy $quota,
        private string $applicationSecret,
        private AuthorizationGateway $authorization,
        private TokenDelegationPreauthorizer $tokenDelegation,
        private TokenRotationPreauthorizer $tokenRotation,
        private ResourceSiteOwnershipWriter $ownership,
        private object $provenance,
    ) {
    }

    public function authenticate(string $email, string $password, string $source): ?AuthenticatedPrincipal
    {
        $normalized = EmailAddress::fromString($email)->value();
        $sourceDigest = hash_hmac('sha256', trim($source) === '' ? 'unknown' : $source, $this->applicationSecret);
        $subjectDigest = hash_hmac('sha256', $normalized, $this->applicationSecret);
        $this->rateLimiter->assertAllowed($subjectDigest, $sourceDigest);

        $row = $this->database->fetchAssociative(sprintf(
            'SELECT u.id, u.security_epoch, p.password_hash FROM %s u INNER JOIN %s p ON p.user_id = u.id '
            . "WHERE u.email_normalized = ? AND u.status = 'active'",
            $this->tables->quoted('users'),
            $this->tables->quoted('password_credentials'),
        ), [$normalized]);
        $valid = $row !== false
            && is_string($row['password_hash'] ?? null)
            && $this->passwords->verify($password, $row['password_hash']);
        $this->rateLimiter->record($subjectDigest, $sourceDigest, $valid);

        if (!$valid || !is_string($row['id'] ?? null)) {
            return null;
        }

        return AuthenticatedPrincipal::issueFromGrantRows(
            $this->provenance,
            $row['id'],
            $this->grantsFor($row['id']),
            'password:' . $row['id'],
            $this->positiveInteger($row['security_epoch'] ?? null),
        );
    }

    public function createInitialAdministrator(
        ExecutionContext $context,
        string $email,
        string $displayName,
        string $password,
    ): string {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('administrator.bootstrap'),
            AuthorizationResource::collection('administrator'),
        );
        $address = EmailAddress::fromString($email);
        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > 191) {
            throw new InvalidArgumentException('The administrator display name must contain 1 to 191 characters.');
        }

        return $this->transactions->transactional(function () use ($address, $displayName, $password): string {
            $userCount = $this->database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $this->tables->quoted('users'),
            ));
            if (
                !is_int($userCount)
                && (!is_string($userCount) || preg_match('/^[0-9]+$/D', $userCount) !== 1)
            ) {
                throw new RuntimeException('The current user count could not be read.');
            }
            if ((int) $userCount !== 0) {
                throw new RuntimeException('The initial administrator can only be created before any user exists.');
            }

            $userId = Uuid::uuid7()->toString();
            $roleId = Uuid::uuid7()->toString();
            $now = $this->clock->now();
            $this->database->insert($this->tables->raw('users'), [
                'id' => $userId,
                'email' => $address->value(),
                'email_normalized' => $address->value(),
                'display_name' => $displayName,
                'status' => 'active',
                'version' => 1,
                'security_epoch' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
            $this->ownership->record(AuthorizationResource::item('user', $userId), SiteContext::default());
            $this->database->insert($this->tables->raw('password_credentials'), [
                'user_id' => $userId,
                'password_hash' => $this->passwords->hash($password),
                'changed_at' => $now,
            ], ['changed_at' => Types::DATETIME_IMMUTABLE]);
            $this->database->insert($this->tables->raw('roles'), [
                'id' => $roleId,
                'code' => 'administrator',
                'name' => 'Administrator',
                'created_at' => $now,
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
            $this->ownership->record(AuthorizationResource::item('role', $roleId), SiteContext::default());
            $this->database->insert($this->tables->raw('user_roles'), [
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_at' => $now,
                'assigned_by' => $userId,
            ], ['assigned_at' => Types::DATETIME_IMMUTABLE]);

            foreach (self::ADMINISTRATOR_CAPABILITIES as $capability) {
                $grantId = Uuid::uuid7()->toString();
                $this->database->insert($this->tables->raw('role_capability_grants'), [
                    'id' => $grantId,
                    'role_id' => $roleId,
                    'capability_code' => $capability,
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                    'granted_at' => $now,
                    'granted_by' => $userId,
                ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
                $this->ownership->record(
                    AuthorizationResource::item('grant', $grantId),
                    SiteContext::default(),
                );
            }

            return $userId;
        });
    }

    private function positiveInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
            throw new InvalidArgumentException('Stored user security epoch is invalid.');
        }

        return (int) $value;
    }

    public function issueAccessToken(
        ExecutionContext $context,
        string $email,
        string $name,
        array $capabilities,
        ?DateTimeImmutable $expiresAt = null,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        ?string $rotatedFrom = null,
    ): array {
        $actorId = $context->actorId();
        $siteIdentifier = $context->site()->identifier();
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 191) {
            throw new InvalidArgumentException('A token name and at least one capability are required.');
        }
        $delegation = $this->tokenDelegation->authorize($context, $email, $capabilities);
        $userId = $delegation->subjectId;
        $tokenContext = AccessTokenContext::fromStrings($audience, $purpose);
        $audience = $tokenContext->audience;
        $purpose = $tokenContext->purpose;
        $siteIdentifier = SiteContext::fromString($siteIdentifier)->identifier();

        $uniqueCapabilities = $delegation->capabilities;

        $now = $this->clock->now();
        $expiresAt ??= $now->modify(self::DEFAULT_TOKEN_LIFETIME);
        if ($expiresAt <= $now || $expiresAt > $now->modify(self::MAXIMUM_TOKEN_LIFETIME)) {
            throw new InvalidArgumentException('API tokens must expire in the future and within 90 days.');
        }
        if ($rotatedFrom !== null && !Uuid::isValid($rotatedFrom)) {
            throw new InvalidArgumentException('The rotated-from token ID must be a canonical UUID.');
        }

        $token = $this->base64Url(random_bytes(48));
        $tokenId = Uuid::uuid7()->toString();
        $this->transactions->transactional(function () use (
            $tokenId,
            $userId,
            $token,
            $name,
            $uniqueCapabilities,
            $expiresAt,
            $now,
            $actorId,
            $audience,
            $purpose,
            $siteIdentifier,
            $rotatedFrom,
            $context,
            $email,
        ): void {
            $epoch = $this->database->fetchOne(sprintf(
                "SELECT security_epoch FROM %s WHERE id = ? AND status = 'active' FOR UPDATE",
                $this->tables->quoted('users'),
            ), [$userId]);
            if (!is_int($epoch) && (!is_string($epoch) || preg_match('/^[0-9]+$/D', $epoch) !== 1)) {
                throw new RuntimeException('The user security epoch could not be locked.');
            }
            $lockedDelegation = $this->tokenDelegation->authorize($context, $email, $uniqueCapabilities);
            if ($lockedDelegation->subjectId !== $userId) {
                throw new RuntimeException('The token subject changed during issuance.');
            }
            $granted = $this->capabilitiesFor($userId, $siteIdentifier);
            foreach ($uniqueCapabilities as $capability) {
                if (!in_array($capability, $granted, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'The user does not grant capability %s.',
                        $capability,
                    ));
                }
            }
            $quotaSql = 'SELECT COUNT(*) FROM %s WHERE subject_id = ? AND site_identifier = ? '
                . 'AND audience = ? AND purpose = ? AND security_epoch = ? '
                . 'AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP';
            $quotaParameters = [$userId, $siteIdentifier, $audience, $purpose, (int) $epoch];
            if ($rotatedFrom !== null) {
                $quotaSql .= ' AND id <> ?';
                $quotaParameters[] = $rotatedFrom;
            }
            $activeCount = $this->database->fetchOne(sprintf(
                $quotaSql,
                $this->tables->quoted('api_tokens'),
            ), $quotaParameters);
            if (
                !is_int($activeCount)
                && (!is_string($activeCount) || preg_match('/^[0-9]+$/D', $activeCount) !== 1)
            ) {
                throw new RuntimeException('The active token quota could not be read.');
            }
            $this->quota->assertAllowed(
                $userId,
                $siteIdentifier,
                $audience,
                $purpose,
                (int) $activeCount,
            );
            $this->database->insert($this->tables->raw('api_tokens'), [
                'id' => $tokenId,
                'subject_id' => $userId,
                'token_digest' => hash('sha256', $token),
                'name' => $name,
                'capabilities' => $uniqueCapabilities,
                'security_epoch' => (int) $epoch,
                'audience' => $audience,
                'purpose' => $purpose,
                'site_identifier' => $siteIdentifier,
                'rotated_from' => $rotatedFrom,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'revocation_reason' => null,
                'created_at' => $now,
                'last_used_at' => null,
            ], [
                'capabilities' => Types::JSON,
                'expires_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->ownership->record(
                AuthorizationResource::item('api_token', $tokenId),
                $context->site(),
            );
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $actorId,
                'token.create',
                'api_token',
                $tokenId,
                'success',
                [
                    'subject_id' => $userId,
                    'capabilities' => $uniqueCapabilities,
                    'audience' => $audience,
                    'purpose' => $purpose,
                    'site_identifier' => $siteIdentifier,
                    'expires_at' => $expiresAt->format(DATE_ATOM),
                    'rotated_from' => $rotatedFrom,
                ],
            ));
        });

        return ['token' => $token, 'token_id' => $tokenId];
    }

    public function rotateAccessToken(
        ExecutionContext $context,
        string $tokenId,
        string $name,
        ?DateTimeImmutable $expiresAt,
    ): array {
        $actorId = $context->actorId();
        $this->tokenRotation->authorize($context, $tokenId);

        return $this->transactions->transactional(function () use (
            $context,
            $name,
            $expiresAt,
            $actorId,
            $tokenId,
        ): array {
            $rotation = $this->tokenRotation->authorize($context, $tokenId, true);
            $created = $this->issueAccessToken(
                $context,
                $rotation->email,
                $name,
                $rotation->capabilities,
                $expiresAt,
                $rotation->audience,
                $rotation->purpose,
                $tokenId,
            );
            $now = $this->clock->now();
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET revoked_at = ?, revocation_reason = ? WHERE id = ? AND revoked_at IS NULL',
                $this->tables->quoted('api_tokens'),
            ), [$now, 'rotated', $tokenId], [Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID]);
            if ($affected !== 1) {
                throw new RuntimeException('The replaced token changed during rotation.');
            }
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $actorId,
                'token.rotate',
                'api_token',
                $tokenId,
                'success',
                ['replacement_token_id' => $created['token_id']],
            ));
            return $created;
        });
    }

    /** @return list<string> */
    private function capabilitiesFor(string $userId, string $siteIdentifier = 'default'): array
    {
        $values = $this->database->fetchFirstColumn(sprintf(
            'SELECT DISTINCT g.capability_code FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id '
            . "WHERE ur.user_id = ? AND (g.scope_type = 'global' "
            . "OR (g.scope_type = 'site' AND g.scope_identifier = ?)) "
            . 'ORDER BY g.capability_code',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('role_capability_grants'),
        ), [$userId, $siteIdentifier]);

        return array_values(array_filter($values, 'is_string'));
    }

    /** @return list<array{capability: string, scope_type: string, scope_identifier: ?string}> */
    private function grantsFor(string $userId): array
    {
        /** @var list<array{capability: string, scope_type: string, scope_identifier: ?string}> */
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT DISTINCT g.capability_code AS capability, g.scope_type, g.scope_identifier '
            . 'FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id WHERE ur.user_id = ? '
            . 'ORDER BY g.capability_code, g.scope_type, g.scope_identifier',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('role_capability_grants'),
        ), [$userId]);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
