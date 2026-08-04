<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Administration;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\CMS\Identity\Application\Administration\CreatedAdministratorSession;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class PostgreSqlAdministratorSessionStore implements AdministratorSessionStore
{
    public function __construct(
        private DatabaseInterface $database,
        private string $schema,
        private ClockInterface $clock,
        private string $applicationSecret,
        private int $lifetimeSeconds = 28_800,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }

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
        $boundId = $id;
        $userId = $principal->subject();
        $tokenDigest = hash('sha256', $token);
        $boundCsrf = $csrf;
        $userAgentDigest = $this->fingerprint($userAgent);
        $createdAt = $this->timestamp($now);
        $lastSeenAt = $this->timestamp($now);
        $expiresAtValue = $this->timestamp($expiresAt);
        $query = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.administrator_sessions'))
            ->columns($this->quoteNames([
                'id',
                'user_id',
                'token_digest',
                'csrf_token',
                'ip_digest',
                'user_agent_digest',
                'created_at',
                'last_seen_at',
                'expires_at',
            ]))
            ->values(
                ':id, :user_id, :token_digest, :csrf_token, NULL, :user_agent_digest, '
                . ':created_at, :last_seen_at, :expires_at',
            )
            ->bind(':id', $boundId, ParameterType::STRING)
            ->bind(':user_id', $userId, ParameterType::STRING)
            ->bind(':token_digest', $tokenDigest, ParameterType::STRING)
            ->bind(':csrf_token', $boundCsrf, ParameterType::STRING)
            ->bind(':user_agent_digest', $userAgentDigest, ParameterType::STRING)
            ->bind(':created_at', $createdAt, ParameterType::STRING)
            ->bind(':last_seen_at', $lastSeenAt, ParameterType::STRING)
            ->bind(':expires_at', $expiresAtValue, ParameterType::STRING);
        $this->database->setQuery($query)->execute();

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
        $tokenDigest = hash('sha256', $token);
        $nowValue = $this->timestamp($now);
        $query = $this->database->getQuery(true)
            ->select([
                $this->quoteName('s.id'),
                $this->quoteName('s.user_id'),
                $this->quoteName('s.csrf_token'),
                $this->quoteName('s.expires_at'),
                $this->quoteName('s.user_agent_digest'),
            ])
            ->from($this->quoteName($this->schema . '.administrator_sessions', 's'))
            ->join(
                'INNER',
                $this->quoteName($this->schema . '.users', 'u')
                    . ' ON ' . $this->quoteName('u.id') . ' = ' . $this->quoteName('s.user_id'),
            )
            ->where($this->quoteName('s.token_digest') . ' = :token_digest')
            ->where($this->quoteName('s.expires_at') . ' > :now')
            ->where($this->quoteName('u.status') . " = 'active'")
            ->bind(':token_digest', $tokenDigest, ParameterType::STRING)
            ->bind(':now', $nowValue, ParameterType::STRING);
        $row = $this->database->setQuery($query)->loadAssoc();

        if (!is_array($row)) {
            return null;
        }

        $storedAgent = $row['user_agent_digest'] ?? null;

        if (!is_string($storedAgent) || !hash_equals($storedAgent, $this->fingerprint($userAgent))) {
            return null;
        }

        $id = $row['id'] ?? null;
        $userId = $row['user_id'] ?? null;
        $csrf = $row['csrf_token'] ?? null;
        $expiresAt = $row['expires_at'] ?? null;

        if (!is_string($id) || !is_string($userId) || !is_string($csrf) || !is_string($expiresAt)) {
            return null;
        }

        $this->touch($id, $now);

        return new AdministratorSession(
            $id,
            AuthenticatedPrincipal::fromStrings($userId, $this->capabilitiesFor($userId)),
            $csrf,
            new DateTimeImmutable($expiresAt),
        );
    }

    public function delete(string $sessionId): void
    {
        $query = $this->database->getQuery(true)
            ->delete($this->quoteName($this->schema . '.administrator_sessions'))
            ->where($this->quoteName('id') . ' = :id')
            ->bind(':id', $sessionId, ParameterType::STRING);
        $this->database->setQuery($query)->execute();
    }

    public function purgeExpired(): int
    {
        $now = $this->timestamp($this->clock->now());
        $query = $this->database->getQuery(true)
            ->delete($this->quoteName($this->schema . '.administrator_sessions'))
            ->where($this->quoteName('expires_at') . ' <= :now')
            ->bind(':now', $now, ParameterType::STRING);
        $this->database->setQuery($query)->execute();

        return $this->database->getAffectedRows();
    }

    /** @return list<string> */
    private function capabilitiesFor(string $userId): array
    {
        $query = $this->database->getQuery(true)
            ->select('DISTINCT ' . $this->quoteName('g.capability_code'))
            ->from($this->quoteName($this->schema . '.user_roles', 'ur'))
            ->join(
                'INNER',
                $this->quoteName($this->schema . '.role_capability_grants', 'g')
                    . ' ON ' . $this->quoteName('g.role_id') . ' = ' . $this->quoteName('ur.role_id'),
            )
            ->where($this->quoteName('ur.user_id') . ' = :user_id')
            ->where($this->quoteName('g.scope_type') . " = 'global'")
            ->order($this->quoteName('g.capability_code'))
            ->bind(':user_id', $userId, ParameterType::STRING);
        $values = $this->database->setQuery($query)->loadColumn();

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    private function touch(string $id, DateTimeImmutable $time): void
    {
        $lastSeenAt = $this->timestamp($time);
        $query = $this->database->getQuery(true)
            ->update($this->quoteName($this->schema . '.administrator_sessions'))
            ->set($this->quoteName('last_seen_at') . ' = :last_seen_at')
            ->where($this->quoteName('id') . ' = :id')
            ->bind(':last_seen_at', $lastSeenAt, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::STRING);
        $this->database->setQuery($query)->execute();
    }

    private function fingerprint(string $userAgent): string
    {
        return hash_hmac('sha256', substr($userAgent, 0, 512), $this->applicationSecret);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function quoteNames(array $names): array
    {
        return array_map(fn (string $name): string => $this->quoteName($name), $names);
    }

    private function quoteName(string $name, ?string $alias = null): string
    {
        $quoted = $this->database->quoteName($name, $alias);

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted identifier.');
        }

        return $quoted;
    }
}
