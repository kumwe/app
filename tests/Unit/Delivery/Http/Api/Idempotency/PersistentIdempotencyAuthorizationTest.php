<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\HttpMutationPreauthorizer;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\PersistentIdempotencyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use RuntimeException;

#[CoversClass(PersistentIdempotencyMiddleware::class)]
#[CoversClass(HttpMutationPreauthorizer::class)]
final class PersistentIdempotencyAuthorizationTest extends TestCase
{
    public function testWrongResourceCannotProbeOrReplayAnExistingKey(): void
    {
        $allowed = '018f22e2-7c8b-7ab0-8f3a-88e8026bb411';
        $denied = '018f22e2-7c8b-7ab0-8f3a-88e8026bb412';
        $principal = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'content.update',
            'scope_type' => 'content',
            'scope_identifier' => $allowed,
        ]]);
        $context = $principal->context(
            \Kumwe\CMS\Application\Authorization\SiteContext::default(),
            \Kumwe\CMS\Application\Authorization\AuthenticationStrength::BearerToken,
            'idempotency-auth-test',
        );
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('insert');
        $database->expects(self::never())->method('fetchAssociative');
        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/v1/content/' . $denied)
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader('stable-key-0001'))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->expectException(AuthorizationDenied::class);
        (new PersistentIdempotencyMiddleware(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->clock(),
            new ProblemDetailsResponseFactory(),
            $this->transactions(),
            new HttpMutationPreauthorizer(
                AuthorizationContext::gateway(),
                (new ReflectionClass(ContentService::class))->newInstanceWithoutConstructor(),
                $this->createStub(AccessControlRepository::class),
                new TokenDelegationPreauthorizer(
                    $this->createStub(AccessControlRepository::class),
                    AuthorizationContext::gateway(),
                ),
            ),
        ))->process($request, $handler);
    }

    public function testThrownMutationReleasesItsLeaseAsFailed(): void
    {
        $principal = AuthorizationContext::principal(['content.create']);
        $context = $principal->context(
            \Kumwe\CMS\Application\Authorization\SiteContext::default(),
            \Kumwe\CMS\Application\Authorization\AuthenticationStrength::BearerToken,
            'idempotency-failure-test',
        );
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('insert');
        $database->expects(self::once())->method('executeStatement')->with(
            self::stringContains('DELETE FROM'),
            self::isType('array'),
        )->willReturn(1);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/content')
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader('stable-key-0002'))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('mutation failed');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mutation failed');
        (new PersistentIdempotencyMiddleware(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->clock(),
            new ProblemDetailsResponseFactory(),
            $this->transactions(),
            new HttpMutationPreauthorizer(
                AuthorizationContext::gateway(),
                (new ReflectionClass(ContentService::class))->newInstanceWithoutConstructor(),
                $this->createStub(AccessControlRepository::class),
                new TokenDelegationPreauthorizer(
                    $this->createStub(AccessControlRepository::class),
                    AuthorizationContext::gateway(),
                ),
            ),
        ))->process($request, $handler);
    }

    public function testUndelegableTokenRequestCannotReserveIdempotencyRow(): void
    {
        $subjectId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb421';
        $principal = AuthorizationContext::principal(['users.manage']);
        $context = $principal->context(
            \Kumwe\CMS\Application\Authorization\SiteContext::default(),
            \Kumwe\CMS\Application\Authorization\AuthenticationStrength::BearerToken,
            'idempotency-token-delegation-test',
        );
        $repository = $this->createStub(AccessControlRepository::class);
        $repository->method('userIdByEmail')->willReturn($subjectId);
        $repository->method('userGrants')->willReturn([[
            'capability' => 'content.publish',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]]);
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('insert');
        $database->expects(self::never())->method('fetchAssociative');
        $body = json_encode([
            'email' => 'target@example.test',
            'name' => 'deployment',
            'capabilities' => ['content.publish'],
        ], JSON_THROW_ON_ERROR);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/tokens')
            ->withBody((new StreamFactory())->createStream($body))
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader('stable-key-0003'))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->expectException(AuthorizationDenied::class);
        (new PersistentIdempotencyMiddleware(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->clock(),
            new ProblemDetailsResponseFactory(),
            $this->transactions(),
            new HttpMutationPreauthorizer(
                AuthorizationContext::gateway(),
                (new ReflectionClass(ContentService::class))->newInstanceWithoutConstructor(),
                $repository,
                new TokenDelegationPreauthorizer($repository, AuthorizationContext::gateway()),
            ),
        ))->process($request, $handler);
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-05T12:00:00+00:00');
            }
        };
    }

    private function transactions(): TransactionManager
    {
        return new class implements TransactionManager {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }
        };
    }
}
