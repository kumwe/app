<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Authentication;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;

final readonly class DoctrineAccessTokenVerifier implements AccessTokenVerifier
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private object $provenance,
    ) {
    }

    public function verify(string $token): ?AuthenticatedPrincipal
    {
        $length = strlen($token);
        if ($length < 32 || $length > 512 || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) !== 1) {
            return null;
        }

        $row = $this->database->fetchAssociative(sprintf(
            'SELECT t.id, t.subject_id, t.capabilities, t.last_used_at, u.security_epoch FROM %s t '
            . 'INNER JOIN %s u ON u.id = t.subject_id '
            . "WHERE t.token_digest = ? AND t.revoked_at IS NULL AND "
            . "(t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP) AND u.status = 'active'",
            $this->tables->quoted('api_tokens'),
            $this->tables->quoted('users'),
        ), [hash('sha256', $token)]);

        if (
            $row === false
            || !is_string($row['id'] ?? null)
            || !is_string($row['subject_id'] ?? null)
        ) {
            return null;
        }

        try {
            $principal = AuthenticatedPrincipal::issueFromGrantRows(
                $this->provenance,
                $row['subject_id'],
                $this->grantsFor($row['subject_id'], $this->decodeCapabilities($row['capabilities'] ?? null)),
                'api-token:' . $row['id'],
                $this->positiveInteger($row['security_epoch'] ?? null),
            );
            $this->touchUsage($row['id'], $row['last_used_at'] ?? null);

            return $principal;
        } catch (InvalidArgumentException | JsonException) {
            return null;
        }
    }

    private function positiveInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
            throw new InvalidArgumentException('Stored user security epoch is invalid.');
        }

        return (int) $value;
    }

    private function touchUsage(string $tokenId, mixed $lastUsedAt): void
    {
        $now = $this->clock->now();
        if ($lastUsedAt !== null) {
            if (!is_string($lastUsedAt)) {
                throw new InvalidArgumentException('Stored token usage time is invalid.');
            }
            try {
                $lastUsed = new DateTimeImmutable($lastUsedAt);
            } catch (\Exception $exception) {
                throw new InvalidArgumentException('Stored token usage time is invalid.', 0, $exception);
            }
            if ($lastUsed > $now->modify('-5 minutes')) {
                return;
            }
        }

        $this->database->executeStatement(sprintf(
            'UPDATE %s SET last_used_at = ? WHERE id = ? AND revoked_at IS NULL',
            $this->tables->quoted('api_tokens'),
        ), [$now, $tokenId], [Types::DATETIME_IMMUTABLE, Types::GUID]);
    }

    /** @return list<string> @throws JsonException */
    private function decodeCapabilities(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        }

        if (!is_array($stored) || !array_is_list($stored)) {
            throw new InvalidArgumentException('Stored token capabilities must be a JSON list.');
        }

        foreach ($stored as $capability) {
            if (!is_string($capability)) {
                throw new InvalidArgumentException('Stored token capabilities must contain strings.');
            }
        }

        return $stored;
    }

    /**
     * @param list<string> $tokenCapabilities
     * @return list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     */
    private function grantsFor(string $subjectId, array $tokenCapabilities): array
    {
        if ($tokenCapabilities === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($tokenCapabilities), '?'));
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT DISTINCT g.capability_code AS capability, g.scope_type, g.scope_identifier '
            . 'FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id '
            . 'WHERE ur.user_id = ? AND g.capability_code IN (%s) '
            . 'ORDER BY g.capability_code, g.scope_type, g.scope_identifier',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('role_capability_grants'),
            $placeholders,
        ), [$subjectId, ...$tokenCapabilities]);

        $grants = [];
        foreach ($rows as $row) {
            if (!is_string($row['capability'] ?? null) || !is_string($row['scope_type'] ?? null)) {
                throw new InvalidArgumentException('A stored principal grant is invalid.');
            }
            $scopeIdentifier = $row['scope_identifier'] ?? null;
            if ($scopeIdentifier !== null && !is_string($scopeIdentifier)) {
                throw new InvalidArgumentException('A stored principal grant scope is invalid.');
            }
            $grants[] = [
                'capability' => $row['capability'],
                'scope_type' => $row['scope_type'],
                'scope_identifier' => $scopeIdentifier,
            ];
        }

        return $grants;
    }
}
