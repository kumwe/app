<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/** Fenced MCP mutation lease whose completion is atomic with the protected database mutation. */
final readonly class McpMutationGuard
{
    private const string LEASE = '+2 minutes';
    private const string RETENTION = '+24 hours';

    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private TransactionManager $transactions,
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
        return $this->execute($context, $operation, $operationId, $input, $mutation, false);
    }

    /**
     * Runs an idempotent secret-issuing operation, but redacts the secret from every replay.
     * @template TResult of array<string, mixed>
     * @param array<string, mixed> $input
     * @param callable(): TResult $mutation
     * @return TResult
     */
    public function runSecret(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        array $input,
        callable $mutation,
    ): array {
        return $this->execute($context, $operation, $operationId, $input, $mutation, true);
    }

    /**
     * @template TResult of array<string, mixed>
     * @param array<string, mixed> $input
     * @param callable(): TResult $mutation
     * @return TResult
     */
    private function execute(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        array $input,
        callable $mutation,
        bool $secretOnce,
    ): array {
        $principal = $context->principal()
            ?? throw new InvalidArgumentException('MCP mutations require a human execution context.');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('MCP operationId must be a stable 16 to 128 character identifier.');
        }

        $operation = 'mcp.' . $operation;
        $digest = hash('sha256', $this->canonicalJson($input));
        $owner = Uuid::uuid7()->toString();
        $replay = $this->acquire($context, $principal, $operation, $operationId, $digest, $owner);
        if ($replay !== null) {
            /** @var TResult $replay */
            return $replay;
        }

        try {
            /** @var TResult $result */
            $result = $this->transactions->transactional(function () use (
                $context,
                $principal,
                $operation,
                $operationId,
                $owner,
                $mutation,
                $secretOnce,
            ): array {
                $this->assertLeaseOwner($context, $principal, $operation, $operationId, $owner);
                $result = $mutation();
                $stored = $secretOnce
                    ? [...array_diff_key($result, ['token' => true]), 'secret_returned' => false]
                    : $result;
                $encoded = json_encode(
                    $stored,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
                $now = $this->clock->now();
                $affected = $this->database->executeStatement(sprintf(
                    "UPDATE %s SET state = 'completed', owner_token = NULL, locked_until = NULL, "
                    . 'lease_owner = NULL, lease_expires_at = NULL, result_status = 200, result_body = ?, '
                    . 'result_body_digest = ?, completed_at = ? '
                    . 'WHERE subject = ? AND operation = ? AND idempotency_key = ? AND owner_token = ? '
                    . "AND authorization_fingerprint = ? AND state = 'in_progress' AND lease_expires_at > ?",
                    $this->tables->quoted('idempotency'),
                ), [
                    $encoded,
                    hash('sha256', $encoded),
                    $now,
                    $principal->subject(),
                    $operation,
                    $operationId,
                    $owner,
                    $context->authorizationFingerprint(),
                    $now,
                ], [
                    Types::TEXT,
                    Types::STRING,
                    Types::DATETIME_IMMUTABLE,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::DATETIME_IMMUTABLE,
                ]);
                $this->assertUpdated($affected, 'The MCP mutation lease was lost before completion.');

                return $result;
            });

            return $result;
        } catch (Throwable $exception) {
            $this->database->executeStatement(sprintf(
                "DELETE FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ? "
                . "AND owner_token = ? AND state = 'in_progress'",
                $this->tables->quoted('idempotency'),
            ), [$principal->subject(), $operation, $operationId, $owner]);
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    private function acquire(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $digest,
        string $owner,
    ): ?array {
        $now = $this->clock->now();
        try {
            $this->insert($context, $principal, $operation, $operationId, $digest, $owner, $now);
            return null;
        } catch (UniqueConstraintViolationException) {
            $row = $this->record($principal, $operation, $operationId);
            if ($this->time($row, 'expires_at') <= $now) {
                if ($this->reset($context, $principal, $operation, $operationId, $digest, $owner, $now, true)) {
                    return null;
                }
                throw new RuntimeException('The expired MCP operation changed while it was being acquired.');
            }

            $storedDigest = $this->string($row, 'request_digest');
            if (!hash_equals($storedDigest, $digest)) {
                throw new InvalidArgumentException('The MCP operationId was already used with different input.');
            }
            if (
                !hash_equals(
                    $this->string($row, 'authorization_fingerprint'),
                    $context->authorizationFingerprint(),
                )
            ) {
                throw new InvalidArgumentException(
                    'The MCP operationId belongs to a different credential or authorization state.',
                );
            }
            if (($row['state'] ?? null) === 'completed') {
                return $this->decodeResult($row);
            }
            if (
                ($row['state'] ?? null) === 'in_progress'
                && $this->time($row, 'lease_expires_at') <= $now
                && $this->reset($context, $principal, $operation, $operationId, $digest, $owner, $now, false)
            ) {
                return null;
            }

            throw new RuntimeException('The MCP operation is already in progress; retry after its lease expires.');
        }
    }

    private function insert(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $digest,
        string $owner,
        DateTimeImmutable $now,
    ): void {
        $leaseUntil = $now->modify(self::LEASE);
        $this->database->insert($this->tables->raw('idempotency'), [
            'id' => Uuid::uuid7()->toString(),
            'idempotency_key' => $operationId,
            'subject' => $principal->subject(),
            'operation' => $operation,
            'request_digest' => $digest,
            'authorization_fingerprint' => $context->authorizationFingerprint(),
            'state' => 'in_progress',
            'owner_token' => $owner,
            'locked_until' => $leaseUntil,
            'lease_owner' => $owner,
            'lease_expires_at' => $leaseUntil,
            'attempt' => 1,
            'created_at' => $now,
            'expires_at' => $now->modify(self::RETENTION),
        ], [
            'locked_until' => Types::DATETIME_IMMUTABLE,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private function reset(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $digest,
        string $owner,
        DateTimeImmutable $now,
        bool $expired,
    ): bool {
        $condition = $expired ? 'expires_at <= ?' : "state = 'in_progress' AND lease_expires_at <= ?";
        $leaseUntil = $now->modify(self::LEASE);
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET request_digest = ?, authorization_fingerprint = ?, state = 'in_progress', "
            . 'owner_token = ?, locked_until = ?, lease_owner = ?, lease_expires_at = ?, attempt = attempt + 1, '
            . 'result_status = NULL, result_body = NULL, result_body_digest = NULL, completed_at = NULL, '
            . 'created_at = ?, expires_at = ? WHERE subject = ? AND operation = ? AND idempotency_key = ? AND %s',
            $this->tables->quoted('idempotency'),
            $condition,
        ), [
            $digest,
            $context->authorizationFingerprint(),
            $owner,
            $leaseUntil,
            $owner,
            $leaseUntil,
            $now,
            $now->modify(self::RETENTION),
            $principal->subject(),
            $operation,
            $operationId,
            $now,
        ], [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
        ]);

        return (string) $affected === '1';
    }

    private function assertLeaseOwner(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $owner,
    ): void {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT owner_token, authorization_fingerprint, state, lease_expires_at FROM %s '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ? FOR UPDATE',
            $this->tables->quoted('idempotency'),
        ), [$principal->subject(), $operation, $operationId]);
        if (
            $row === false
            || ($row['owner_token'] ?? null) !== $owner
            || ($row['state'] ?? null) !== 'in_progress'
            || !hash_equals($this->string($row, 'authorization_fingerprint'), $context->authorizationFingerprint())
            || $this->time($row, 'lease_expires_at') <= $this->clock->now()
        ) {
            throw new RuntimeException('The MCP mutation lease is no longer owned by this request.');
        }
    }

    /** @return array<string, mixed> */
    private function record(AuthenticatedPrincipal $principal, string $operation, string $operationId): array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT request_digest, authorization_fingerprint, state, result_body, result_body_digest, '
            . 'lease_expires_at, expires_at FROM %s '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $this->tables->quoted('idempotency'),
        ), [$principal->subject(), $operation, $operationId]);
        if ($row === false) {
            throw new RuntimeException('The MCP idempotency record disappeared during acquisition.');
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeResult(array $row): array
    {
        $body = $this->string($row, 'result_body');
        if (!hash_equals($this->string($row, 'result_body_digest'), hash('sha256', $body))) {
            throw new RuntimeException('The stored MCP operation result failed its integrity check.');
        }
        $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result) || array_is_list($result)) {
            throw new RuntimeException('The stored MCP operation result is invalid.');
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('The MCP idempotency field %s is invalid.', $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function time(array $row, string $field): DateTimeImmutable
    {
        $value = $row[$field] ?? null;
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('The MCP idempotency field %s is invalid.', $field));
        }

        return new DateTimeImmutable($value);
    }

    private function assertUpdated(int|string $affected, string $message): void
    {
        if ((string) $affected !== '1') {
            throw new RuntimeException($message);
        }
    }

    /** @param array<string, mixed> $input @throws JsonException */
    private function canonicalJson(array $input): string
    {
        ksort($input, SORT_STRING);
        return json_encode($input, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
