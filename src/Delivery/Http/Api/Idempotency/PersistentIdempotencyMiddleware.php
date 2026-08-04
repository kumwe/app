<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use JsonException;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Laminas\Diactoros\Response\JsonResponse;
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
        private DatabaseInterface $database,
        private ClockInterface $clock,
        private ProblemDetailsResponseFactory $problems,
        private string $schema,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }
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
        $insert = sprintf(
            "INSERT INTO %s (id, idempotency_key, subject, operation, request_digest, state, created_at, expires_at) "
            . "VALUES (%s, %s, %s, %s, %s, 'in_progress', %s, %s) "
            . 'ON CONFLICT (subject, operation, idempotency_key) DO NOTHING',
            $this->table(),
            $this->quote(Uuid::uuid7()->toString()),
            $this->quote((string) $key),
            $this->quote($principal->subject()),
            $this->quote($operation),
            $this->quote($digest),
            $this->quote($now->format('Y-m-d H:i:s.uP')),
            $this->quote($now->modify('+24 hours')->format('Y-m-d H:i:s.uP')),
        );
        $this->database->setQuery($insert)->execute();

        if ($this->database->getAffectedRows() === 0) {
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
        $row = $this->database->setQuery(sprintf(
            'SELECT request_digest, state, result_status, result_body, result_headers, expires_at FROM %s '
            . 'WHERE subject = %s AND operation = %s AND idempotency_key = %s',
            $this->table(),
            $this->quote($subject),
            $this->quote($operation),
            $this->quote($key),
        ))->loadAssoc();

        if (!is_array($row)) {
            throw new RuntimeException('The idempotency record could not be loaded.');
        }

        $expiresAt = new DateTimeImmutable((string) ($row['expires_at'] ?? ''));

        if ($expiresAt <= $this->clock->now()) {
            $this->reset($subject, $operation, $key, $digest);

            return null;
        }

        if (!hash_equals((string) ($row['request_digest'] ?? ''), $digest)) {
            return $this->problems->create(
                422,
                'Idempotency Key Reused',
                'This Idempotency-Key was already used for a different request.',
                'urn:kumwe:problem:idempotency-key-reused',
                (string) $request->getUri(),
            );
        }

        if ((string) ($row['state'] ?? '') === 'completed') {
            $body = $this->jsonObject($row['result_body'] ?? null);
            $headers = $this->headers($row['result_headers'] ?? null);
            $headers['Idempotency-Replayed'] = 'true';

            return new JsonResponse($body, (int) ($row['result_status'] ?? 200), $headers);
        }

        if ((string) ($row['state'] ?? '') === 'failed') {
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
        $this->database->setQuery(sprintf(
            "UPDATE %s SET request_digest = %s, state = 'in_progress', result_status = NULL, "
            . "result_body = NULL, result_body_digest = NULL, result_headers = '{}'::jsonb, "
            . 'completed_at = NULL, created_at = CURRENT_TIMESTAMP, '
            . "expires_at = CURRENT_TIMESTAMP + interval '24 hours' "
            . 'WHERE subject = %s AND operation = %s AND idempotency_key = %s',
            $this->table(),
            $this->quote($digest),
            $this->quote($subject),
            $this->quote($operation),
            $this->quote($key),
        ))->execute();
    }

    private function complete(string $subject, string $operation, string $key, ResponseInterface $response): void
    {
        $decoded = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('Idempotent HTTP responses must contain JSON arrays or objects.');
        }

        $body = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = [];

        foreach (['Content-Type', 'Cache-Control', 'ETag', 'Location'] as $name) {
            if ($response->hasHeader($name)) {
                $headers[$name] = $response->getHeaderLine($name);
            }
        }

        $encodedHeaders = json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $sql = sprintf(
            "UPDATE %s SET state = 'completed', result_status = %d, result_body = %s::jsonb, "
            . 'result_body_digest = %s, result_headers = %s::jsonb, completed_at = CURRENT_TIMESTAMP '
            . "WHERE subject = %s AND operation = %s AND idempotency_key = %s AND state = 'in_progress'",
            $this->table(),
            $response->getStatusCode(),
            $this->quote($body),
            $this->quote(hash('sha256', $body)),
            $this->quote($encodedHeaders),
            $this->quote($subject),
            $this->quote($operation),
            $this->quote($key),
        );
        $this->database->setQuery($sql)->execute();
    }

    private function markFailed(string $subject, string $operation, string $key): void
    {
        $this->database->setQuery(sprintf(
            "UPDATE %s SET state = 'failed' WHERE subject = %s AND operation = %s "
            . "AND idempotency_key = %s AND state = 'in_progress'",
            $this->table(),
            $this->quote($subject),
            $this->quote($operation),
            $this->quote($key),
        ))->execute();
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $stored): array
    {
        try {
            $decoded = is_string($stored) ? json_decode($stored, true, 64, JSON_THROW_ON_ERROR) : $stored;
        } catch (JsonException $exception) {
            throw new RuntimeException('An idempotency result contains invalid JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('An idempotency result must contain a JSON array or object.');
        }

        return $decoded;
    }

    /** @return array<string, string> */
    private function headers(mixed $stored): array
    {
        $headers = $this->jsonObject($stored);

        foreach ($headers as $name => $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Stored idempotency response headers must contain strings.');
            }
        }

        /** @var array<string, string> $headers */
        return $headers;
    }

    private function table(): string
    {
        $quoted = $this->database->quoteName($this->schema . '.idempotency');

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted table.');
        }

        return $quoted;
    }

    private function quote(string $value): string
    {
        $quoted = $this->database->quote($value);

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted value.');
        }

        return $quoted;
    }
}
