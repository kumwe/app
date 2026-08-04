<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Administrator\Http\Middleware;

use Kumwe\CMS\Administrator\Http\Middleware\AdministratorAuthorizationMiddleware;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use LogicException;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(AdministratorAuthorizationMiddleware::class)]
#[UsesClass(AuthenticatedPrincipal::class)]
#[UsesClass(Capability::class)]
final class AdministratorAuthorizationMiddlewareTest extends TestCase
{
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testBypassesRequestsOutsideAdministratorArea(): void
    {
        $response = (new AdministratorAuthorizationMiddleware())->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/'),
            $this->successfulHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAllowsAdministratorWithEveryRequiredCapability(): void
    {
        $principal = AuthenticatedPrincipal::fromStrings(self::SUBJECT, [
            'navigation.manage',
            'administrator.access',
        ]);
        $response = (new AdministratorAuthorizationMiddleware())->process(
            $this->request(['administrator.access', 'navigation.manage'], $principal),
            $this->successfulHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDeniesAdministratorMissingAnExactCapability(): void
    {
        $principal = AuthenticatedPrincipal::fromStrings(self::SUBJECT, ['administrator.access']);
        $response = (new AdministratorAuthorizationMiddleware())->process(
            $this->request(['navigation.manage'], $principal),
            $this->neverHandler(),
        );
        $problem = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertIsArray($problem);
        self::assertSame(
            'Capability navigation.manage is required for this administrator operation.',
            $problem['detail'] ?? null,
        );
    }

    public function testFailsClosedWhenAdministratorRouteHasNoCapabilityPolicy(): void
    {
        $principal = AuthenticatedPrincipal::fromStrings(self::SUBJECT, ['administrator.access']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must declare required capabilities');

        (new AdministratorAuthorizationMiddleware())->process(
            $this->request([], $principal, false),
            $this->neverHandler(),
        );
    }

    /** @param list<string> $capabilities */
    private function request(
        array $capabilities,
        AuthenticatedPrincipal $principal,
        bool $configurePolicy = true,
    ): ServerRequestInterface {
        $route = new Route(
            '/administrator/navigation',
            $this->createStub(MiddlewareInterface::class),
            ['GET'],
            'administrator.navigation',
        );

        if ($configurePolicy) {
            $route->setOptions([
                AdministratorAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => $capabilities,
            ]);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/navigation')
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal);
    }

    private function successfulHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('', 204);
            }
        };
    }

    private function neverHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        return $handler;
    }
}
