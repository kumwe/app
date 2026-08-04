<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Laminas\Diactoros\Response;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class PersistentIdempotencyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $request->getAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE);
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$key instanceof IdempotencyKey || !$principal instanceof AuthenticatedPrincipal) {
            throw new RuntimeException('Persistent idempotency requires an authenticated request and validated key.');
        }

        $operation = strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath();
        $digest = hash('sha256', implode("\n", [
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            $request->getHeaderLine('Content-Type'),
            $request->getHeaderLine('If-Match'),
            (string) $request->getBody(),
        ]));
        $now = $this->clock->now();
        $inserted = true;
        try {
            $this->database->insert($this->tables->raw('idempotency'), [
                'id' => Uuid::uuid7()->toString(),
                'idempotency_key' => (string) $key,
                'subject' => $principal->subject(),
                'operation' => $operation,
                'request_digest' => $digest,
                'state' => 'in_progress',
                'result_status' => null,
                'result_body' => null,
                'result_headers' => null,
                'result_body_digest' => null,
                'created_at' => $now,
                'completed_at' => null,
                'expires_at' => $now->modify('+24 hours'),
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            $inserted = false;
        }

        if (!$inserted) {
            $replay = $this->replay($principal->subject(), $operation, (string) $key, $digest, $request);

            if ($replay !== null) {
                return $replay;
            }
        }

        $response = $handler->handle($request);

        if ($response->getStatusCode() >= 500) {
            $this->markFailed($principal->subject(), $operation, (string) $key);

            return $response;
        }

        $this->complete($principal->subject(), $operation, (string) $key, $response);

        return $response;
    }

    private function replay(
        string $subject,
        string $operation,
        string $key,
        string $digest,
        ServerRequestInterface $request,
    ): ?ResponseInterface {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT request_digest, state, result_status, result_body, result_body_digest, result_headers, '
            . 'expires_at FROM %s '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key]);

        if ($row === false) {
            throw new RuntimeException('The idempotency record could not be loaded.');
        }

        /** @var array<string, mixed> $row */
        $expiresAt = new DateTimeImmutable($this->requiredString($row, 'expires_at'));

        if ($expiresAt <= $this->clock->now()) {
            $this->reset($subject, $operation, $key, $digest);

            return null;
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

        $state = $this->requiredString($row, 'state');

        if ($state === 'completed') {
            $body = $this->storedString($row, 'result_body');
            $bodyDigest = $this->requiredString($row, 'result_body_digest');
            if (!hash_equals($bodyDigest, hash('sha256', $body))) {
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

        if ($state === 'failed') {
            $this->reset($subject, $operation, $key, $digest);

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

    private function reset(string $subject, string $operation, string $key, string $digest): void
    {
        $now = $this->clock->now();
        $this->database->executeStatement(sprintf(
            "UPDATE %s SET request_digest = ?, state = 'in_progress', result_status = NULL, "
            . 'result_body = NULL, result_body_digest = NULL, result_headers = NULL, completed_at = NULL, '
            . 'created_at = ?, expires_at = ? WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $this->tables->quoted('idempotency'),
        ), [$digest, $now, $now->modify('+24 hours'), $subject, $operation, $key], [
            Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE,
            Types::STRING, Types::STRING, Types::STRING,
        ]);
    }

    private function complete(string $subject, string $operation, string $key, ResponseInterface $response): void
    {
        $body = (string) $response->getBody();
        $headers = [];

        foreach (['Content-Type', 'Cache-Control', 'ETag', 'Location'] as $name) {
            if ($response->hasHeader($name)) {
                $headers[$name] = $response->getHeaderLine($name);
            }
        }

        $this->database->executeStatement(sprintf(
            "UPDATE %s SET state = 'completed', result_status = ?, result_body = ?, result_body_digest = ?, "
            . "result_headers = ?, completed_at = ? WHERE subject = ? AND operation = ? AND idempotency_key = ? "
            . "AND state = 'in_progress'",
            $this->tables->quoted('idempotency'),
        ), [
            $response->getStatusCode(),
            $body,
            hash('sha256', $body),
            $headers,
            $this->clock->now(),
            $subject,
            $operation,
            $key,
        ], [
            Types::INTEGER, Types::TEXT, Types::STRING, Types::JSON, Types::DATETIME_IMMUTABLE,
            Types::STRING, Types::STRING, Types::STRING,
        ]);
    }

    private function markFailed(string $subject, string $operation, string $key): void
    {
        $this->database->executeStatement(sprintf(
            "UPDATE %s SET state = 'failed' WHERE subject = ? AND operation = ? "
            . "AND idempotency_key = ? AND state = 'in_progress'",
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key]);
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
