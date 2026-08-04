<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
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
     * @param array<string, mixed> $input
     * @param callable(): array<string, mixed> $mutation
     * @return array<string, mixed>
     */
    public function run(
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        array $input,
        callable $mutation,
    ): array {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('MCP operationId must be a stable 16 to 128 character identifier.');
        }
        $digest = hash('sha256', $this->canonicalJson($input));
        $now = $this->clock->now();

        try {
            $this->database->insert($this->tables->raw('idempotency'), [
                'id' => Uuid::uuid7()->toString(),
                'idempotency_key' => $operationId,
                'subject' => $principal->subject(),
                'operation' => 'mcp.' . $operation,
                'request_digest' => $digest,
                'state' => 'in_progress',
                'created_at' => $now,
                'expires_at' => $now->modify('+24 hours'),
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($principal, $operation, $operationId, $digest);
        }

        try {
            $result = $mutation();
            $this->database->executeStatement(sprintf(
                "UPDATE %s SET state = 'completed', result_status = 200, result_body = ?, completed_at = ? "
                . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
                $this->tables->quoted('idempotency'),
            ), [$result, $this->clock->now(), $principal->subject(), 'mcp.' . $operation, $operationId], [
                Types::JSON, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING, Types::STRING,
            ]);

            return $result;
        } catch (Throwable $exception) {
            $this->database->delete($this->tables->raw('idempotency'), [
                'subject' => $principal->subject(),
                'operation' => 'mcp.' . $operation,
                'idempotency_key' => $operationId,
            ]);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function replay(
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $digest,
    ): array {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT request_digest, state, result_body FROM %s '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $this->tables->quoted('idempotency'),
        ), [$principal->subject(), 'mcp.' . $operation, $operationId]);
        if ($row === false || !hash_equals((string) ($row['request_digest'] ?? ''), $digest)) {
            throw new InvalidArgumentException('The MCP operationId was already used with different input.');
        }
        if (($row['state'] ?? null) !== 'completed') {
            throw new RuntimeException('The MCP operation is already in progress; retry later with the same ID.');
        }
        $result = $row['result_body'] ?? null;
        if (is_string($result)) {
            $result = json_decode($result, true, 64, JSON_THROW_ON_ERROR);
        }
        if (!is_array($result) || array_is_list($result)) {
            throw new RuntimeException('The stored MCP operation result is invalid.');
        }
        /** @var array<string, mixed> $result */
        return $result;
    }

    /** @param array<string, mixed> $input @throws JsonException */
    private function canonicalJson(array $input): string
    {
        ksort($input, SORT_STRING);

        return json_encode($input, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
