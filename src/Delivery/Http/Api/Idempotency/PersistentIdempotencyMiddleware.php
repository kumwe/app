<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Laminas\Diactoros\Response;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final readonly class PersistentIdempotencyMiddleware implements MiddlewareInterface
{
    private const int PROCESSING_LEASE_SECONDS = 900;
    private const int RETENTION_SECONDS = 86_400;

    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private ProblemDetailsResponseFactory $problems,
        private TransactionManager $transactions,
        private HttpMutationPreauthorizer $preauthorization,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $request->getAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE);
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        $context = $request->getAttribute(ExecutionContext::REQUEST_ATTRIBUTE);
        if (
            !$key instanceof IdempotencyKey
            || !$principal instanceof AuthenticatedPrincipal
            || !$context instanceof ExecutionContext
            || $context->principal() !== $principal
        ) {
            throw new RuntimeException('Persistent idempotency requires an authenticated request and validated key.');
        }

        // Replay is an authorization-sensitive read and must never precede exact use-case authorization.
        $this->preauthorization->authorize($request, $context);
        $subject = $principal->subject();
        $authorizationFingerprint = $context->authorizationFingerprint();
        $operation = strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath();
        $keyValue = (string) $key;
        $digest = $this->requestDigest($request);
        $ownerToken = Uuid::uuid7()->toString();
        $now = $this->clock->now();

        try {
            $this->database->insert($this->tables->raw('idempotency'), [
                'id' => Uuid::uuid7()->toString(),
                'idempotency_key' => $keyValue,
                'subject' => $subject,
                'operation' => $operation,
                'request_digest' => $digest,
                'authorization_fingerprint' => $authorizationFingerprint,
                'state' => 'in_progress',
                'owner_token' => $ownerToken,
                'locked_until' => $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
                'lease_owner' => $ownerToken,
                'lease_expires_at' => $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
                'result_status' => null,
                'result_body' => null,
                'result_headers' => null,
                'result_body_digest' => null,
                'created_at' => $now,
                'completed_at' => null,
                'expires_at' => $now->modify('+' . self::RETENTION_SECONDS . ' seconds'),
            ], [
                'locked_until' => Types::DATETIME_IMMUTABLE,
                'lease_expires_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            $result = $this->replayOrAcquire(
                $subject,
                $operation,
                $keyValue,
                $digest,
                $authorizationFingerprint,
                $ownerToken,
                $request,
            );
            if ($result instanceof ResponseInterface) {
                return $result;
            }
        }

        try {
            return $this->transactions->transactional(function () use (
                $handler,
                $request,
                $subject,
                $operation,
                $keyValue,
                $ownerToken,
            ): ResponseInterface {
                $response = $handler->handle($request);
                if ($response->getStatusCode() >= 500) {
                    throw new ServerFailureResponse($response);
                }
                $this->complete($subject, $operation, $keyValue, $ownerToken, $response);
                return $response;
            });
        } catch (ServerFailureResponse $failure) {
            $this->release($subject, $operation, $keyValue, $ownerToken, true);
            return $failure->response;
        } catch (Throwable $failure) {
            $this->release($subject, $operation, $keyValue, $ownerToken, false);
            throw $failure;
        }
    }

    private function replayOrAcquire(
        string $subject,
        string $operation,
        string $key,
        string $digest,
        string $authorizationFingerprint,
        string $ownerToken,
        ServerRequestInterface $request,
    ): ?ResponseInterface {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT id, request_digest, authorization_fingerprint, state, owner_token, locked_until, '
                . 'result_status, result_body, result_body_digest, result_headers, expires_at FROM %s '
                . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
                $this->tables->quoted('idempotency'),
            ), [$subject, $operation, $key]);
            if ($row === false) {
                throw new RuntimeException('The idempotency record could not be loaded.');
            }

            /** @var array<string, mixed> $row */
            $now = $this->clock->now();
            $id = $this->requiredString($row, 'id');
            $expiresAt = new DateTimeImmutable($this->requiredString($row, 'expires_at'));
            if ($expiresAt <= $now) {
                if ($this->acquireExpired($id, $digest, $authorizationFingerprint, $ownerToken, $now)) {
                    return null;
                }
                continue;
            }

            if (!hash_equals($this->requiredString($row, 'request_digest'), $digest)) {
                return $this->problems->create(
                    422,
                    'Idempotency Key Reused',
                    'This Idempotency-Key was already used for a different request.',
                    'urn:kumwe:problem:idempotency-key-reused',
                    (string) $request->getUri(),
                );
            }

            if (
                !hash_equals(
                    $this->requiredString($row, 'authorization_fingerprint'),
                    $authorizationFingerprint,
                )
            ) {
                return $this->problems->create(
                    409,
                    'Authorization Context Changed',
                    'This Idempotency-Key belongs to a different credential or authorization state.',
                    'urn:kumwe:problem:idempotency-authorization-changed',
                    (string) $request->getUri(),
                );
            }

            $state = $this->requiredString($row, 'state');
            if ($state === 'completed') {
                return $this->replay($row);
            }
            if (
                $state === 'failed' && $this->acquireFailed(
                    $id,
                    $digest,
                    $authorizationFingerprint,
                    $ownerToken,
                    $now,
                )
            ) {
                return null;
            }

            $lockedUntil = $row['locked_until'] ?? null;
            if (
                $state === 'in_progress'
                && (!is_string($lockedUntil) || new DateTimeImmutable($lockedUntil) <= $now)
                && $this->acquireStale($id, $authorizationFingerprint, $ownerToken, $now)
            ) {
                return null;
            }

            return $this->problems->create(
                409,
                'Operation In Progress',
                'An operation with this Idempotency-Key is still in progress.',
                'urn:kumwe:problem:idempotency-in-progress',
                (string) $request->getUri(),
            );
        }

        return $this->problems->create(
            409,
            'Operation In Progress',
            'Ownership of this idempotent operation changed concurrently; retry the request.',
            'urn:kumwe:problem:idempotency-in-progress',
            (string) $request->getUri(),
        );
    }

    /** @param array<string, mixed> $row */
    private function replay(array $row): ResponseInterface
    {
        $body = $this->storedString($row, 'result_body');
        if (!hash_equals($this->requiredString($row, 'result_body_digest'), hash('sha256', $body))) {
            throw new RuntimeException('The stored idempotency response body failed its integrity check.');
        }
        $headers = $this->headers($row['result_headers'] ?? null);
        $headers['Idempotency-Replayed'] = 'true';
        $response = (new Response())->withStatus($this->integer($row, 'result_status'));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response->getBody()->write($body);
        return $response;
    }

    private function acquireExpired(
        string $id,
        string $digest,
        string $authorizationFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
    ): bool {
        return $this->reset(
            $id,
            $digest,
            $authorizationFingerprint,
            $ownerToken,
            $now,
            'expires_at <= ?',
            [$now],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    private function acquireFailed(
        string $id,
        string $digest,
        string $authorizationFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
    ): bool {
        return $this->reset(
            $id,
            $digest,
            $authorizationFingerprint,
            $ownerToken,
            $now,
            "state = 'failed'",
            [],
            [],
        );
    }

    private function acquireStale(
        string $id,
        string $authorizationFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
    ): bool {
        return $this->reset(
            $id,
            null,
            $authorizationFingerprint,
            $ownerToken,
            $now,
            "state = 'in_progress' AND (locked_until IS NULL OR locked_until <= ?)",
            [$now],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @param list<mixed> $conditionValues
     * @param list<string> $conditionTypes
     */
    private function reset(
        string $id,
        ?string $digest,
        string $authorizationFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
        string $condition,
        array $conditionValues,
        array $conditionTypes,
    ): bool {
        $digestAssignment = $digest === null ? '' : 'request_digest = ?, ';
        $values = $digest === null ? [] : [$digest];
        $types = $digest === null ? [] : [Types::STRING];
        $values = array_merge($values, [
            $authorizationFingerprint,
            $ownerToken,
            $ownerToken,
            $now,
            $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
            $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
            $now->modify('+' . self::RETENTION_SECONDS . ' seconds'),
            $id,
        ], $conditionValues);
        $types = array_merge($types, [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
        ], $conditionTypes);
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET %sauthorization_fingerprint = ?, state = 'in_progress', owner_token = ?, "
            . 'lease_owner = ?, created_at = ?, locked_until = ?, lease_expires_at = ?, expires_at = ?, '
            . 'result_status = NULL, result_body = NULL, result_body_digest = NULL, '
            . 'result_headers = NULL, completed_at = NULL WHERE id = ? AND %s',
            $this->tables->quoted('idempotency'),
            $digestAssignment,
            $condition,
        ), $values, $types);
        return (string) $affected === '1';
    }

    private function complete(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        ResponseInterface $response,
    ): void {
        $body = (string) $response->getBody();
        $headers = [];
        foreach (['Content-Type', 'Cache-Control', 'ETag', 'Location'] as $name) {
            if ($response->hasHeader($name)) {
                $headers[$name] = $response->getHeaderLine($name);
            }
        }
        $now = $this->clock->now();
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET state = 'completed', owner_token = NULL, locked_until = NULL, "
            . 'lease_owner = NULL, lease_expires_at = NULL, result_status = ?, result_body = ?, '
            . 'result_body_digest = ?, result_headers = ?, completed_at = ? '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ? AND state = '
            . "'in_progress' AND owner_token = ? AND locked_until > ?",
            $this->tables->quoted('idempotency'),
        ), [
            $response->getStatusCode(), $body, hash('sha256', $body), $headers, $now,
            $subject, $operation, $key, $ownerToken, $now,
        ], [
            Types::INTEGER, Types::TEXT, Types::STRING, Types::JSON, Types::DATETIME_IMMUTABLE,
            Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]);
        $this->assertOwner($affected);
    }

    private function release(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        bool $assertOwner,
    ): void {
        $affected = $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ? '
            . "AND state = 'in_progress' AND owner_token = ?",
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key, $ownerToken]);
        if ($assertOwner) {
            $this->assertOwner($affected);
        }
    }

    private function requestDigest(ServerRequestInterface $request): string
    {
        return hash('sha256', implode("\n", [
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            $request->getHeaderLine('Content-Type'),
            $request->getHeaderLine('If-Match'),
            (string) $request->getBody(),
        ]));
    }

    private function assertOwner(int|string $affected): void
    {
        if ((string) $affected !== '1') {
            throw new RuntimeException('The request no longer owns the active idempotency lease.');
        }
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $stored): array
    {
        try {
            $decoded = is_string($stored) ? json_decode($stored, true, 64, JSON_THROW_ON_ERROR) : $stored;
        } catch (JsonException $exception) {
            throw new RuntimeException('An idempotency result contains invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('An idempotency result must contain a JSON object.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array<non-empty-string, string> */
    private function headers(mixed $stored): array
    {
        $headers = $this->jsonObject($stored);
        foreach ($headers as $name => $value) {
            if ($name === '' || !is_string($value)) {
                throw new RuntimeException('Stored idempotency response headers must contain strings.');
            }
        }
        /** @var array<non-empty-string, string> $headers */
        return $headers;
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Idempotency field %s is invalid.', $field));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function storedString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Idempotency field %s is invalid.', $field));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Idempotency field %s is not an integer.', $field));
        }
        return (int) $value;
    }
}
