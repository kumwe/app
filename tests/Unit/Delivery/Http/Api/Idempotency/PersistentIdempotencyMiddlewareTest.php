<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Persistence\TransactionManager;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\HttpMutationPreauthorizer;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\PersistentIdempotencyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\CMS\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineIdempotencyLedger;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(PersistentIdempotencyMiddleware::class)]
#[CoversClass(DoctrineIdempotencyLedger::class)]
final class PersistentIdempotencyMiddlewareTest extends TestCase
{
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testHandlerExceptionImmediatelyReleasesOwnedRecord(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('insert');
        $database->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $values): int {
                self::assertStringContainsString('DELETE FROM', $sql);
                self::assertSame(self::SUBJECT, $values[0]);
                self::assertSame('POST /api/v1/content', $values[1]);
                self::assertSame('request-1234', $values[2]);
                self::assertIsString($values[3]);
                return 1;
            },
        );
        $middleware = $this->middleware($database);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('handler failed');
        $middleware->process($this->request(), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('handler failed');
            }
        });
    }

    public function testCompletionIsRejectedWhenOwnershipFenceWasLost(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('insert');
        $call = 0;
        $database->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$call): int {
                ++$call;
                if ($call === 1) {
                    self::assertStringContainsString('owner_token = ? AND locked_until > ?', $sql);
                } else {
                    self::assertStringContainsString('DELETE FROM', $sql);
                }
                return 0;
            },
        );
        $middleware = $this->middleware($database);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer owns');
        $middleware->process($this->request(), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(status: 201);
            }
        });
    }

    private function middleware(Connection $database): PersistentIdempotencyMiddleware
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-05T10:00:00+00:00');
            }
        };
        return new PersistentIdempotencyMiddleware(
            new DoctrineIdempotencyLedger($database, new TableNames($database, 'kumwe_'), $clock),
            $clock,
            new ProblemDetailsResponseFactory(),
            new class implements TransactionManager {
                public function transactional(callable $operation): mixed
                {
                    return $operation();
                }

                public function afterCommit(callable $operation): void
                {
                    $operation();
                }

                public function afterRollback(callable $operation): void
                {
                }
            },
            new HttpMutationPreauthorizer(
                AuthorizationContext::gateway(),
                (new \ReflectionClass(ContentService::class))->newInstanceWithoutConstructor(),
                $repository = $this->createStub(AccessControlRepository::class),
                new TokenDelegationPreauthorizer(
                    $repository,
                    AuthorizationContext::gateway(),
                ),
                new TokenRotationPreauthorizer(
                    $repository,
                    AuthorizationContext::gateway(),
                    new TokenDelegationPreauthorizer($repository, AuthorizationContext::gateway()),
                ),
            ),
        );
    }

    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $name): string => '"' . $name . '"',
        );
        return $database;
    }

    private function request(): ServerRequestInterface
    {
        $principal = AuthorizationContext::principal(['content.create'], self::SUBJECT);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'idempotency-middleware-test',
        );

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/content')
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                IdempotencyKey::fromHeader('request-1234'),
            )
            ->withAttribute(
                AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
                $principal,
            )
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context);
    }
}
