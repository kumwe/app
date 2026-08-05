<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

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

/** Atomically persists token mutations while retaining only replay-safe metadata. */
final readonly class SecretOnceIdempotencyMiddleware implements MiddlewareInterface
{
    private const LEASE = '+2 minutes';

    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private ProblemDetailsResponseFactory $problems,
        private HttpMutationPreauthorizer $preauthorization,
        private TransactionManager $transactions,
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
            throw new RuntimeException('Secret idempotency requires an authenticated request and validated key.');
        }

        $this->preauthorization->authorize($request, $context);
        $operation = strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath();
        $digest = $this->requestDigest($request);
        $owner = bin2hex(random_bytes(32));
        $replay = $this->acquire(
            $context,
            $principal,
            $operation,
            (string) $key,
            $digest,
            $owner,
            $request,
        );
        if ($replay !== null) {
            return $replay;
        }

        try {
            return $this->transactions->transactional(function () use (
                $principal,
                $context,
                $operation,
                $key,
                $owner,
                $request,
                $handler,
            ): ResponseInterface {
                $this->assertLeaseOwner($context, $principal->subject(), $operation, (string) $key, $owner);
                $response = $handler->handle($request);
                if ($response->getStatusCode() >= 500) {
                    throw new SecretOnceResponseRollback($response);
                }
                [$storedBody, $headers] = $this->replaySafeResponse($response);
                $affected = $this->database->executeStatement(sprintf(
                    "UPDATE %s SET state = 'completed', owner_token = NULL, lease_owner = NULL, "
                    . 'result_status = ?, result_body = ?, '
                    . 'result_body_digest = ?, result_headers = ?, completed_at = ?, lease_expires_at = NULL '
                    . "WHERE subject = ? AND operation = ? AND idempotency_key = ? AND owner_token = ? "
                    . "AND authorization_fingerprint = ? AND state = 'in_progress' AND lease_expires_at > ?",
                    $this->tables->quoted('idempotency'),
                ), [
                    $response->getStatusCode(),
                    $storedBody,
                    hash('sha256', $storedBody),
                    $headers,
                    $this->clock->now(),
                    $principal->subject(),
                    $operation,
                    (string) $key,
                    $owner,
                    $context->authorizationFingerprint(),
                    $this->clock->now(),
                ], [
                    Types::INTEGER,
                    Types::TEXT,
                    Types::STRING,
                    Types::JSON,
                    Types::DATETIME_IMMUTABLE,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::DATETIME_IMMUTABLE,
                ]);
                if ($affected !== 1) {
                    throw new RuntimeException('The token mutation lease was lost before completion.');
                }
                return $response;
            });
        } catch (SecretOnceResponseRollback $rollback) {
            $this->release($principal->subject(), $operation, (string) $key, $owner);
            return $rollback->response;
        } catch (Throwable $exception) {
            $this->release($principal->subject(), $operation, (string) $key, $owner);
            throw $exception;
        }
    }

    private function acquire(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $key,
        string $digest,
        string $owner,
        ServerRequestInterface $request,
    ): ?ResponseInterface {
        $now = $this->clock->now();
        try {
            $this->database->insert($this->tables->raw('idempotency'), [
                'id' => Uuid::uuid7()->toString(),
                'idempotency_key' => $key,
                'subject' => $principal->subject(),
                'operation' => $operation,
                'request_digest' => $digest,
                'authorization_fingerprint' => $context->authorizationFingerprint(),
                'state' => 'in_progress',
                'owner_token' => $owner,
                'lease_owner' => $owner,
                'lease_expires_at' => $now->modify(self::LEASE),
                'attempt' => 1,
                'created_at' => $now,
                'expires_at' => $now->modify('+24 hours'),
            ], [
                'lease_expires_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
            return null;
        } catch (UniqueConstraintViolationException) {
            $row = $this->record($principal->subject(), $operation, $key);
            $storedDigest = $row['request_digest'] ?? null;
            if (!is_string($storedDigest) || !hash_equals($storedDigest, $digest)) {
                return $this->problems->create(
                    422,
                    'Idempotency Key Reused',
                    'This Idempotency-Key was already used for a different request.',
                    'urn:kumwe:problem:idempotency-key-reused',
                    (string) $request->getUri(),
                );
            }
            $storedFingerprint = $row['authorization_fingerprint'] ?? null;
            if (
                !is_string($storedFingerprint)
                || !hash_equals($storedFingerprint, $context->authorizationFingerprint())
            ) {
                return $this->problems->create(
                    409,
                    'Authorization Context Changed',
                    'This Idempotency-Key belongs to a different credential or authorization state.',
                    'urn:kumwe:problem:idempotency-authorization-changed',
                    (string) $request->getUri(),
                );
            }
            if (($row['state'] ?? null) === 'completed') {
                return $this->replay($row, $principal->subject(), $operation, $key);
            }
            $affected = $this->database->executeStatement(sprintf(
                "UPDATE %s SET request_digest = ?, authorization_fingerprint = ?, state = 'in_progress', "
                . 'owner_token = ?, lease_owner = ?, '
                . 'lease_expires_at = ?, attempt = attempt + 1, result_status = NULL, result_body = NULL, '
                . 'result_body_digest = NULL, result_headers = NULL, completed_at = NULL, created_at = ?, '
                . 'expires_at = ? WHERE subject = ? AND operation = ? AND idempotency_key = ? '
                . "AND (state = 'failed' OR expires_at <= ? "
                . "OR (state = 'in_progress' AND lease_expires_at <= ?))",
                $this->tables->quoted('idempotency'),
            ), [
                $digest,
                $context->authorizationFingerprint(),
                $owner,
                $owner,
                $now->modify(self::LEASE),
                $now,
                $now->modify('+24 hours'),
                $principal->subject(),
                $operation,
                $key,
                $now,
                $now,
            ], [
                Types::STRING,
                Types::STRING,
                Types::STRING,
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
                Types::DATETIME_IMMUTABLE,
                Types::DATETIME_IMMUTABLE,
                Types::STRING,
                Types::STRING,
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
                Types::DATETIME_IMMUTABLE,
            ]);
            if ($affected === 1) {
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
    }

    private function assertLeaseOwner(
        ExecutionContext $context,
        string $subject,
        string $operation,
        string $key,
        string $owner,
    ): void {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT owner_token, authorization_fingerprint, state, lease_expires_at FROM %s '
            . 'WHERE subject = ? AND operation = ? '
            . 'AND idempotency_key = ? FOR UPDATE',
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key]);
        if (
            $row === false
            || ($row['owner_token'] ?? null) !== $owner
            || ($row['state'] ?? null) !== 'in_progress'
            || !is_string($row['authorization_fingerprint'] ?? null)
            || !hash_equals($row['authorization_fingerprint'], $context->authorizationFingerprint())
            || !is_string($row['lease_expires_at'] ?? null)
            || new \DateTimeImmutable($row['lease_expires_at']) <= $this->clock->now()
        ) {
            throw new RuntimeException('The token mutation lease is no longer owned by this request.');
        }
    }

    /** @return array{string, array<string, string>} */
    private function replaySafeResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            try {
                $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('A token response must contain a JSON object.', 0, $exception);
            }
            if (!is_array($decoded) || array_is_list($decoded) || !is_string($decoded['token'] ?? null)) {
                throw new RuntimeException('A successful token response must contain a one-time token secret.');
            }
            unset($decoded['token']);
            $decoded['secret_returned'] = false;
            $body = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $headers = [];
        foreach (['Content-Type', 'Cache-Control', 'Location'] as $name) {
            if ($response->hasHeader($name)) {
                $headers[$name] = $response->getHeaderLine($name);
            }
        }
        return [$body, $headers];
    }

    /** @param array<string, mixed> $row */
    private function replay(array $row, string $subject, string $operation, string $key): ResponseInterface
    {
        $body = $row['result_body'] ?? null;
        $digest = $row['result_body_digest'] ?? null;
        if (!is_string($body) || !is_string($digest) || !hash_equals($digest, hash('sha256', $body))) {
            throw new RuntimeException('The stored token response failed its integrity check.');
        }
        $decoded = json_decode($body, true);
        if (is_array($decoded) && !array_is_list($decoded) && array_key_exists('token', $decoded)) {
            unset($decoded['token']);
            $decoded['secret_returned'] = false;
            $body = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET result_body = ?, result_body_digest = ? '
                . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
                $this->tables->quoted('idempotency'),
            ), [$body, hash('sha256', $body), $subject, $operation, $key]);
        }
        $headers = $row['result_headers'] ?? [];
        if (is_string($headers)) {
            $headers = json_decode($headers, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($headers) || array_is_list($headers)) {
            throw new RuntimeException('Stored token response headers are invalid.');
        }
        $response = (new Response())->withStatus($this->integer($row['result_status'] ?? null));
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)) {
                throw new RuntimeException('Stored token response headers are invalid.');
            }
            $response = $response->withHeader($name, $value);
        }
        $response = $response->withHeader('Idempotency-Replayed', 'true');
        $response->getBody()->write($body);
        return $response;
    }

    /** @return array<string, mixed> */
    private function record(string $subject, string $operation, string $key): array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT request_digest, authorization_fingerprint, state, result_status, result_body, '
            . 'result_body_digest, result_headers, '
            . 'expires_at, lease_expires_at '
            . 'FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key]);
        if ($row === false) {
            throw new RuntimeException('The token idempotency record disappeared during acquisition.');
        }
        return $row;
    }

    private function release(string $subject, string $operation, string $key, string $owner): void
    {
        $this->database->executeStatement(sprintf(
            "DELETE FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ? "
            . "AND owner_token = ? AND state = 'in_progress'",
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key, $owner]);
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

    private function integer(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('Stored token response status is invalid.');
        }
        return (int) $value;
    }
}

final class SecretOnceResponseRollback extends RuntimeException
{
    public function __construct(public readonly ResponseInterface $response)
    {
        parent::__construct('The secret-returning request failed and was rolled back.');
    }
}
