<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Administration;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\CMS\Identity\Application\Administration\CreatedAdministratorSession;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

final readonly class DoctrineAdministratorSessionStore implements AdministratorSessionStore
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private string $applicationSecret,
        private int $lifetimeSeconds = 28_800,
    ) {
        if ($lifetimeSeconds < 300 || $lifetimeSeconds > 604_800) {
            throw new InvalidArgumentException('Administrator sessions must last between five minutes and seven days.');
        }
    }

    public function create(AuthenticatedPrincipal $principal, string $userAgent): CreatedAdministratorSession
    {
        $id = Uuid::uuid7()->toString();
        $token = $this->base64Url(random_bytes(48));
        $csrf = $this->base64Url(random_bytes(32));
        $now = $this->clock->now();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', $this->lifetimeSeconds)));
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
            'SELECT s.id, s.user_id, s.csrf_token, s.expires_at, s.user_agent_digest '
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

        $expiresAt = $row['expires_at'] instanceof DateTimeImmutable
            ? $row['expires_at']
            : new DateTimeImmutable((string) $row['expires_at']);
        $this->database->update(
            $this->tables->raw('administrator_sessions'),
            ['last_seen_at' => $now],
            ['id' => $row['id']],
            ['last_seen_at' => Types::DATETIME_IMMUTABLE],
        );

        return new AdministratorSession(
            $row['id'],
            AuthenticatedPrincipal::fromStrings($row['user_id'], $this->capabilitiesFor($row['user_id'])),
            $row['csrf_token'],
            $expiresAt,
        );
    }

    public function delete(string $sessionId): void
    {
        $this->database->delete($this->tables->raw('administrator_sessions'), ['id' => $sessionId]);
    }

    public function purgeExpired(): int
    {
        return $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE expires_at <= ?',
            $this->tables->quoted('administrator_sessions'),
        ), [$this->clock->now()], [Types::DATETIME_IMMUTABLE]);
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

    private function fingerprint(string $userAgent): string
    {
        return hash_hmac('sha256', substr($userAgent, 0, 512), $this->applicationSecret);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
