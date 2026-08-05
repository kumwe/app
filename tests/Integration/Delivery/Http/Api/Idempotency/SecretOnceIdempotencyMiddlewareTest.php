<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\SecretOnceIdempotencyMiddleware;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(SecretOnceIdempotencyMiddleware::class)]
final class SecretOnceIdempotencyMiddlewareTest extends TestCase
{
    public function testTokenSecretIsReturnedOnceAndNeverStoredInIdempotencyState(): void
    {
        [$middleware, $database, $tables, $context] = $this->services();
        $marker = Uuid::uuid7()->toString();
        $request = $this->request('token-secret-' . $marker, $context);
        $handler = $this->handler($database, $tables, 'secret-once-' . $marker, false);

        $first = $middleware->process($request, $handler);
        $replay = $middleware->process($request, $handler);
        $firstBody = json_decode((string) $first->getBody(), true, 16, JSON_THROW_ON_ERROR);
        $replayBody = json_decode((string) $replay->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('raw-token-secret', $firstBody['token']);
        self::assertTrue($firstBody['secret_returned']);
        self::assertArrayNotHasKey('token', $replayBody);
        self::assertFalse($replayBody['secret_returned']);
        self::assertSame('true', $replay->getHeaderLine('Idempotency-Replayed'));
        self::assertSame(1, $handler->calls);

        $stored = $database->fetchOne(sprintf(
            'SELECT result_body FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), 'POST /api/v1/tokens', 'token-secret-' . $marker]);
        self::assertIsString($stored);
        self::assertStringNotContainsString('raw-token-secret', $stored);
    }

    public function testMutationAndLeaseAreRolledBackTogetherAtFailureKillPoint(): void
    {
        [$middleware, $database, $tables, $context] = $this->services();
        $marker = Uuid::uuid7()->toString();
        $request = $this->request('token-failure-' . $marker, $context);
        $setting = 'secret-failure-' . $marker;

        try {
            $middleware->process($request, $this->handler($database, $tables, $setting, true));
            self::fail('The simulated kill point must escape the middleware.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated process failure', $exception->getMessage());
        }
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE setting_key = ?',
            $tables->quoted('site_settings'),
        ), [$setting]));
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), 'POST /api/v1/tokens', 'token-failure-' . $marker]));
    }

    public function testLegacyCompletedTokenResponseIsRedactedAndScrubbedBeforeReplay(): void
    {
        [$middleware, $database, $tables, $context] = $this->services();
        $marker = Uuid::uuid7()->toString();
        $key = 'legacy-token-' . $marker;
        $request = $this->request($key, $context);
        $legacy = json_encode([
            'token' => 'legacy-plaintext-secret',
            'token_id' => Uuid::uuid7()->toString(),
        ], JSON_THROW_ON_ERROR);
        $now = new DateTimeImmutable();
        $database->insert($tables->raw('idempotency'), [
            'id' => Uuid::uuid7()->toString(),
            'idempotency_key' => $key,
            'subject' => $context->actorId(),
            'operation' => 'POST /api/v1/tokens',
            'request_digest' => $this->digest($request),
            'authorization_fingerprint' => $context->authorizationFingerprint(),
            'state' => 'completed',
            'result_status' => 201,
            'result_body' => $legacy,
            'result_headers' => ['Content-Type' => 'application/json'],
            'result_body_digest' => hash('sha256', $legacy),
            'owner_token' => null,
            'lease_expires_at' => null,
            'attempt' => 0,
            'created_at' => $now,
            'completed_at' => $now,
            'expires_at' => $now->modify('+1 day'),
        ], [
            'result_headers' => Types::JSON,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $handler = $this->handler($database, $tables, 'legacy-replay-' . $marker, false);

        $response = $middleware->process($request, $handler);
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('token', $body);
        self::assertFalse($body['secret_returned']);
        self::assertSame(0, $handler->calls);
        $stored = $database->fetchOne(sprintf(
            'SELECT result_body FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), 'POST /api/v1/tokens', $key]);
        self::assertIsString($stored);
        self::assertStringNotContainsString('legacy-plaintext-secret', $stored);
    }

    /** @return array{SecretOnceIdempotencyMiddleware, Connection, TableNames, ExecutionContext} */
    private function services(): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $middleware = $container->get(SecretOnceIdempotencyMiddleware::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(SecretOnceIdempotencyMiddleware::class, $middleware);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        return [$middleware, $database, $tables, TestKernelFactory::administratorContext($container)];
    }

    private function request(string $key, ExecutionContext $context): ServerRequestInterface
    {
        $principal = $context->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/tokens')
            ->withHeader('Content-Type', 'application/json')
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader($key))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context);
        $request->getBody()->write(json_encode([
            'email' => 'integration-administrator@example.test',
            'capabilities' => ['users.manage'],
        ], JSON_THROW_ON_ERROR));
        return $request;
    }

    private function digest(ServerRequestInterface $request): string
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

    private function handler(
        Connection $database,
        TableNames $tables,
        string $setting,
        bool $fail,
    ): RequestHandlerInterface {
        return new class ($database, $tables, $setting, $fail) implements RequestHandlerInterface {
            public int $calls = 0;

            public function __construct(
                private readonly Connection $database,
                private readonly TableNames $tables,
                private readonly string $setting,
                private readonly bool $fail,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                $this->database->insert($this->tables->raw('site_settings'), [
                    'setting_key' => $this->setting,
                    'setting_value' => ['created' => true],
                    'version' => 1,
                    'updated_by' => null,
                    'updated_at' => new DateTimeImmutable(),
                ], ['setting_value' => Types::JSON, 'updated_at' => Types::DATETIME_IMMUTABLE]);
                if ($this->fail) {
                    throw new RuntimeException('simulated process failure');
                }
                return new JsonResponse([
                    'token' => 'raw-token-secret',
                    'token_id' => Uuid::uuid7()->toString(),
                    'secret_returned' => true,
                ], 201, ['Cache-Control' => 'no-store']);
            }
        };
    }
}
