<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Administration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class DoctrineAdministratorIdentityGateway implements AdministratorIdentityGateway
{
    /** @var list<string> */
    private const ADMINISTRATOR_CAPABILITIES = [
        'administrator.access', 'automation.manage', 'content.archive', 'content.create', 'content.delete',
        'content.publish', 'content.read', 'content.restore', 'content.review', 'content.submit',
        'content.unpublish', 'content.update', 'extensions.manage', 'navigation.manage', 'settings.manage',
        'users.manage',
    ];

    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private PasswordHasher $passwords,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private AuthenticationRateLimiter $rateLimiter,
        private AuditRecorder $audit,
        private string $applicationSecret,
    ) {
    }

    public function authenticate(string $email, string $password, string $source): ?AuthenticatedPrincipal
    {
        $normalized = EmailAddress::fromString($email)->value();
        $sourceDigest = hash_hmac('sha256', trim($source) === '' ? 'unknown' : $source, $this->applicationSecret);
        $subjectDigest = hash_hmac('sha256', $normalized, $this->applicationSecret);
        $this->rateLimiter->assertAllowed($subjectDigest, $sourceDigest);

        $row = $this->database->fetchAssociative(sprintf(
            'SELECT u.id, p.password_hash FROM %s u INNER JOIN %s p ON p.user_id = u.id '
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

        return AuthenticatedPrincipal::fromStrings($row['id'], $this->capabilitiesFor($row['id']));
    }

    public function createInitialAdministrator(string $email, string $displayName, string $password): string
    {
        $address = EmailAddress::fromString($email);
        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > 191) {
            throw new InvalidArgumentException('The administrator display name must contain 1 to 191 characters.');
        }

        return $this->transactions->transactional(function () use ($address, $displayName, $password): string {
            if (
                (int) $this->database->fetchOne(sprintf(
                    'SELECT COUNT(*) FROM %s',
                    $this->tables->quoted('users'),
                )) !== 0
            ) {
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
                'created_at' => $now,
                'updated_at' => $now,
            ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
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
            $this->database->insert($this->tables->raw('user_roles'), [
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_at' => $now,
                'assigned_by' => $userId,
            ], ['assigned_at' => Types::DATETIME_IMMUTABLE]);

            foreach (self::ADMINISTRATOR_CAPABILITIES as $capability) {
                $this->database->insert($this->tables->raw('role_capability_grants'), [
                    'id' => Uuid::uuid7()->toString(),
                    'role_id' => $roleId,
                    'capability_code' => $capability,
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                    'granted_at' => $now,
                    'granted_by' => $userId,
                ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
            }

            return $userId;
        });
    }

    public function issueAccessToken(
        string $email,
        string $name,
        array $capabilities,
        ?DateTimeImmutable $expiresAt = null,
        ?string $actorId = null,
    ): array {
        $normalized = EmailAddress::fromString($email)->value();
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 191 || !array_is_list($capabilities) || $capabilities === []) {
            throw new InvalidArgumentException('A token name and at least one capability are required.');
        }

        $userId = $this->database->fetchOne(sprintf(
            "SELECT id FROM %s WHERE email_normalized = ? AND status = 'active'",
            $this->tables->quoted('users'),
        ), [$normalized]);

        if (!is_string($userId)) {
            throw new InvalidArgumentException('The requested active user does not exist.');
        }

        $granted = $this->capabilitiesFor($userId);

        foreach ($capabilities as $capability) {
            if (!is_string($capability) || !in_array($capability, $granted, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The user does not grant capability %s.',
                    (string) $capability,
                ));
            }
        }

        $token = $this->base64Url(random_bytes(48));
        $tokenId = Uuid::uuid7()->toString();
        $actorId ??= 'system:cli';
        $uniqueCapabilities = array_values(array_unique($capabilities));
        $now = $this->clock->now();
        $this->transactions->transactional(function () use (
            $tokenId,
            $userId,
            $token,
            $name,
            $uniqueCapabilities,
            $expiresAt,
            $now,
            $actorId,
        ): void {
            $this->database->insert($this->tables->raw('api_tokens'), [
                'id' => $tokenId,
                'subject_id' => $userId,
                'token_digest' => hash('sha256', $token),
                'name' => $name,
                'capabilities' => $uniqueCapabilities,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'created_at' => $now,
                'last_used_at' => null,
            ], [
                'capabilities' => Types::JSON,
                'expires_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $actorId,
                'token.create',
                'api_token',
                $tokenId,
                'success',
                ['subject_id' => $userId, 'capabilities' => $uniqueCapabilities],
            ));
        });

        return ['token' => $token, 'token_id' => $tokenId];
    }

    /** @return list<string> */
    private function capabilitiesFor(string $userId): array
    {
        $values = $this->database->fetchFirstColumn(sprintf(
            'SELECT DISTINCT g.capability_code FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id '
            . "WHERE ur.user_id = ? AND g.scope_type = 'global' ORDER BY g.capability_code",
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('role_capability_grants'),
        ), [$userId]);

        return array_values(array_filter($values, 'is_string'));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
