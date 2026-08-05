<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final readonly class McpMutationGuard
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @template TResult of array<string, mixed>
     * @param array<string, mixed> $input
     * @param callable(): TResult $mutation
     * @return TResult
     */
    public function run(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        array $input,
        callable $mutation,
    ): array {
        $principal = $context->principal()
            ?? throw new InvalidArgumentException('MCP mutations require a human execution context.');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('MCP operationId must be a stable 16 to 128 character identifier.');
        }
        $digest = hash('sha256', $this->canonicalJson($input));
        $now = $this->clock->now();
        $authorizationFingerprint = $context->authorizationFingerprint();
        $leaseOwner = bin2hex(random_bytes(24));

        try {
            $this->database->insert($this->tables->raw('idempotency'), [
                'id' => Uuid::uuid7()->toString(),
                'idempotency_key' => $operationId,
                'subject' => $principal->subject(),
                'operation' => 'mcp.' . $operation,
                'request_digest' => $digest,
                'authorization_fingerprint' => $authorizationFingerprint,
                'lease_owner' => $leaseOwner,
                'lease_expires_at' => $now->modify('+2 minutes'),
                'state' => 'in_progress',
                'created_at' => $now,
                'expires_at' => $now->modify('+24 hours'),
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'lease_expires_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            $replayed = $this->replay(
                $principal,
                $operation,
                $operationId,
                $digest,
                $authorizationFingerprint,
                $leaseOwner,
            );
            if ($replayed !== null) {
                /** @var TResult $replayed */
                return $replayed;
            }
        }

        try {
            $result = $mutation();
            $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $affected = $this->database->executeStatement(sprintf(
                "UPDATE %s SET state = 'completed', result_status = 200, result_body = ?, "
                . 'result_body_digest = ?, completed_at = ? '
                . "WHERE subject = ? AND operation = ? AND idempotency_key = ? AND state = 'in_progress' "
                . 'AND lease_owner = ?',
                $this->tables->quoted('idempotency'),
            ), [
                $encoded,
                hash('sha256', $encoded),
                $this->clock->now(),
                $principal->subject(),
                'mcp.' . $operation,
                $operationId,
                $leaseOwner,
            ], [
                Types::TEXT,
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
                Types::STRING,
                Types::STRING,
                Types::STRING,
                Types::STRING,
            ]);
            if ((string) $affected !== '1') {
                throw new RuntimeException('The MCP mutation lease was lost before its result could be committed.');
            }

            return $result;
        } catch (Throwable $exception) {
            $this->database->executeStatement(sprintf(
                "UPDATE %s SET state = 'failed' WHERE subject = ? AND operation = ? "
                . "AND idempotency_key = ? AND state = 'in_progress' AND lease_owner = ?",
                $this->tables->quoted('idempotency'),
            ), [$principal->subject(), 'mcp.' . $operation, $operationId, $leaseOwner]);
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    private function replay(
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $digest,
        string $authorizationFingerprint,
        string $leaseOwner,
    ): ?array {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT request_digest, authorization_fingerprint, state, result_body, result_body_digest, '
            . 'lease_expires_at, expires_at FROM %s '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $this->tables->quoted('idempotency'),
        ), [$principal->subject(), 'mcp.' . $operation, $operationId]);
        if ($row === false) {
            throw new RuntimeException('The MCP idempotency record could not be loaded.');
        }
        if ($this->expired($row['expires_at'] ?? null)) {
            if (
                $this->acquire(
                    $principal,
                    $operation,
                    $operationId,
                    $digest,
                    $authorizationFingerprint,
                    $leaseOwner,
                )
            ) {
                return null;
            }

            throw new RuntimeException('The MCP operation lease changed while it was being acquired.');
        }
        $storedDigest = $row['request_digest'] ?? null;
        if (!is_string($storedDigest) || !hash_equals($storedDigest, $digest)) {
            throw new InvalidArgumentException('The MCP operationId was already used with different input.');
        }
        $storedFingerprint = $row['authorization_fingerprint'] ?? null;
        if (!is_string($storedFingerprint) || !hash_equals($storedFingerprint, $authorizationFingerprint)) {
            throw new InvalidArgumentException(
                'The MCP operationId belongs to a different credential or authorization state.',
            );
        }
        $state = $row['state'] ?? null;
        if ($state === 'completed') {
            $resultBody = $row['result_body'] ?? null;
            $resultDigest = $row['result_body_digest'] ?? null;
            if (
                !is_string($resultBody)
                || !is_string($resultDigest)
                || !hash_equals($resultDigest, hash('sha256', $resultBody))
            ) {
                throw new RuntimeException('The stored MCP operation result failed its integrity check.');
            }
            $result = json_decode($resultBody, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($result) || array_is_list($result)) {
                throw new RuntimeException('The stored MCP operation result is invalid.');
            }
            /** @var array<string, mixed> $result */
            return $result;
        }
        if (
            $state === 'failed'
            || $this->leaseExpired($row['lease_expires_at'] ?? null)
        ) {
            if (
                $this->acquire(
                    $principal,
                    $operation,
                    $operationId,
                    $digest,
                    $authorizationFingerprint,
                    $leaseOwner,
                )
            ) {
                return null;
            }
        }
        throw new RuntimeException('The MCP operation is already in progress; retry later with the same ID.');
    }

    private function acquire(
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $digest,
        string $authorizationFingerprint,
        string $leaseOwner,
    ): bool {
        $now = $this->clock->now();
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET state = 'in_progress', request_digest = ?, authorization_fingerprint = ?, "
            . 'lease_owner = ?, lease_expires_at = ?, result_body = NULL, result_body_digest = NULL, '
            . 'completed_at = NULL, created_at = ?, expires_at = ? '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ? '
            . "AND (state = 'failed' OR lease_expires_at <= ? OR expires_at <= ?)",
            $this->tables->quoted('idempotency'),
        ), [
            $digest, $authorizationFingerprint, $leaseOwner, $now->modify('+2 minutes'),
            $now, $now->modify('+24 hours'), $principal->subject(), 'mcp.' . $operation, $operationId,
            $now, $now,
        ]);

        return (string) $affected === '1';
    }

    private function leaseExpired(mixed $stored): bool
    {
        if ($stored instanceof \DateTimeInterface) {
            return $stored <= $this->clock->now();
        }
        if (!is_string($stored)) {
            throw new RuntimeException('The MCP idempotency lease is invalid.');
        }

        return new \DateTimeImmutable($stored) <= $this->clock->now();
    }

    private function expired(mixed $stored): bool
    {
        return $this->leaseExpired($stored);
    }

    /** @param array<string, mixed> $input @throws JsonException */
    private function canonicalJson(array $input): string
    {
        ksort($input, SORT_STRING);

        return json_encode($input, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
