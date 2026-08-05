<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Administration;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\CMS\Identity\Application\Administration\CreatedAdministratorSession;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class DoctrineAdministratorSessionStore implements AdministratorSessionStore
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private string $applicationSecret,
        private AuthorizationGateway $authorization,
        private TransactionManager $transactions,
        private ResourceSiteOwnershipWriter $ownership,
        private object $provenance,
        private int $lifetimeSeconds = 28_800,
    ) {
        if ($lifetimeSeconds < 300 || $lifetimeSeconds > 604_800) {
            throw new InvalidArgumentException('Administrator sessions must last between five minutes and seven days.');
        }
    }

    public function create(ExecutionContext $context, string $userAgent): CreatedAdministratorSession
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('administrator.access'),
            AuthorizationResource::collection('administrator_session'),
        );
        $principal = $context->principal()
            ?? throw new InvalidArgumentException('Administrator sessions require a human principal.');
        $id = Uuid::uuid7()->toString();
        $token = $this->base64Url(random_bytes(48));
        $csrf = $this->base64Url(random_bytes(32));
        $now = $this->clock->now();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', $this->lifetimeSeconds)));
        $this->transactions->transactional(function () use (
            $context,
            $principal,
            $id,
            $token,
            $csrf,
            $userAgent,
            $now,
            $expiresAt,
        ): void {
            $this->database->insert($this->tables->raw('administrator_sessions'), [
                'id' => $id,
                'user_id' => $principal->subject(),
                'token_digest' => hash('sha256', $token),
                'csrf_token' => $csrf,
                'ip_digest' => null,
                'user_agent_digest' => $this->fingerprint($userAgent),
                'created_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $expiresAt,
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'last_seen_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->ownership->record(
                AuthorizationResource::item('administrator_session', $id),
                $context->site(),
            );
        });

        return new CreatedAdministratorSession(
            $token,
            new AdministratorSession($id, $principal, $csrf, $expiresAt),
        );
    }

    public function find(string $token, string $userAgent): ?AdministratorSession
    {
        if (strlen($token) < 43 || strlen($token) > 128 || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            return null;
        }

        $now = $this->clock->now();
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT s.id, s.user_id, s.csrf_token, s.expires_at, s.user_agent_digest, u.security_epoch '
            . 'FROM %s s INNER JOIN %s u ON u.id = s.user_id '
            . "WHERE s.token_digest = ? AND s.expires_at > ? AND u.status = 'active'",
            $this->tables->quoted('administrator_sessions'),
            $this->tables->quoted('users'),
        ), [hash('sha256', $token), $now], [Types::STRING, Types::DATETIME_IMMUTABLE]);

        if (
            $row === false
            || !is_string($row['user_agent_digest'] ?? null)
            || !hash_equals($row['user_agent_digest'], $this->fingerprint($userAgent))
            || !is_string($row['id'] ?? null)
            || !is_string($row['user_id'] ?? null)
            || !is_string($row['csrf_token'] ?? null)
        ) {
            return null;
        }

        $storedExpiry = $row['expires_at'] ?? null;
        if (!$storedExpiry instanceof DateTimeImmutable && !is_string($storedExpiry)) {
            return null;
        }
        $expiresAt = $storedExpiry instanceof DateTimeImmutable
            ? $storedExpiry
            : new DateTimeImmutable($storedExpiry);
        $this->database->update(
            $this->tables->raw('administrator_sessions'),
            ['last_seen_at' => $now],
            ['id' => $row['id']],
            ['last_seen_at' => Types::DATETIME_IMMUTABLE],
        );

        return new AdministratorSession(
            $row['id'],
            AuthenticatedPrincipal::issueFromGrantRows(
                $this->provenance,
                $row['user_id'],
                $this->grantsFor($row['user_id']),
                'administrator-session:' . $row['id'],
                $this->positiveInteger($row['security_epoch'] ?? null),
            ),
            $row['csrf_token'],
            $expiresAt,
        );
    }

    private function positiveInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
            throw new InvalidArgumentException('Stored user security epoch is invalid.');
        }

        return (int) $value;
    }

    public function delete(ExecutionContext $context, string $sessionId): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('administrator.access'),
            AuthorizationResource::item('administrator_session', $sessionId),
        );
        $this->transactions->transactional(function () use ($context, $sessionId): void {
            $affected = $this->database->delete(
                $this->tables->raw('administrator_sessions'),
                ['id' => $sessionId],
            );
            if ((string) $affected !== '1') {
                throw new InvalidArgumentException('The administrator session does not exist.');
            }
            $this->ownership->remove(
                AuthorizationResource::item('administrator_session', $sessionId),
                $context->site(),
            );
        });
    }

    public function purgeExpired(ExecutionContext $context): int
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::collection('administrator_session'),
        );
        return $this->transactions->transactional(function () use ($context): int {
            $now = $this->clock->now();
            $sessionOwnershipId = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? 'CAST(s.id AS VARCHAR)'
                : 's.id';
            $sessionIds = $this->database->fetchFirstColumn(sprintf(
                'SELECT s.id FROM %s s INNER JOIN %s o ON o.resource_type = ? AND o.resource_id = %s '
                . 'AND o.site_identifier = ? WHERE s.expires_at <= ? ORDER BY s.id FOR UPDATE',
                $this->tables->quoted('administrator_sessions'),
                $this->tables->quoted('resource_site_ownership'),
                $sessionOwnershipId,
            ), ['administrator_session', $context->site()->identifier(), $now], [
                Types::STRING,
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
            ]);

            foreach ($sessionIds as $sessionId) {
                if (!is_string($sessionId) || $sessionId === '') {
                    throw new RuntimeException('An expired administrator session identifier is invalid.');
                }
                $affected = $this->database->delete(
                    $this->tables->raw('administrator_sessions'),
                    ['id' => $sessionId],
                );
                if ((string) $affected !== '1') {
                    throw new RuntimeException('An expired administrator session changed during deletion.');
                }
                $this->ownership->remove(
                    AuthorizationResource::item('administrator_session', $sessionId),
                    $context->site(),
                );
            }

            return count($sessionIds);
        });
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

    private function fingerprint(string $userAgent): string
    {
        return hash_hmac('sha256', substr($userAgent, 0, 512), $this->applicationSecret);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
