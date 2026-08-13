<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Administrator\Http\Middleware;

use DateTimeImmutable;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorAuthorizationMiddleware;
use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\CMS\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
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
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Twig\Loader\ArrayLoader;

#[CoversClass(AdministratorAuthorizationMiddleware::class)]
#[UsesClass(AuthenticatedPrincipal::class)]
#[UsesClass(Capability::class)]
#[UsesClass(AdministratorRenderer::class)]
#[UsesClass(RecoveryAdministratorRenderer::class)]
#[UsesClass(AdministratorSession::class)]
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
        $principal = AuthorizationContext::principal([
            'navigation.manage',
            'administrator.access',
        ], self::SUBJECT);
        $response = (new AdministratorAuthorizationMiddleware())->process(
            $this->request(['administrator.access', 'navigation.manage'], $principal),
            $this->successfulHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDeniesAdministratorMissingAnExactCapability(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
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

    public function testBrowserNavigationDenialRendersTheThemedPageWithTheActorsOwnNavigation(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $middleware = new AdministratorAuthorizationMiddleware($this->renderer());
        $response = $middleware->process(
            $this->request(['navigation.manage'], $principal)
                ->withHeader('Accept', 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8')
                ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $this->session($principal)),
            $this->neverHandler(),
        );
        $body = (string) $response->getBody();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('missing:navigation.manage', $body);
        self::assertStringContainsString('[core.dashboard]', $body, 'The held navigation must be offered.');
        self::assertStringNotContainsString(
            '[core.navigation]',
            $body,
            'Navigation the actor cannot open must stay hidden on the denial page.',
        );
    }

    public function testNonHtmlAcceptsKeepTheProblemDocumentEvenWithARendererWired(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $middleware = new AdministratorAuthorizationMiddleware($this->renderer());
        $response = $middleware->process(
            $this->request(['navigation.manage'], $principal)
                ->withHeader('Accept', 'application/json')
                ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $this->session($principal)),
            $this->neverHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function testMutationDenialsKeepTheProblemDocumentEvenWhenTheBrowserAcceptsHtml(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $middleware = new AdministratorAuthorizationMiddleware($this->renderer());
        $response = $middleware->process(
            $this->request(['navigation.manage'], $principal, method: 'POST')
                ->withHeader('Accept', 'text/html')
                ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $this->session($principal)),
            $this->neverHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function testHtmlDenialFallsBackToTheProblemDocumentWithoutASession(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $middleware = new AdministratorAuthorizationMiddleware($this->renderer());
        $response = $middleware->process(
            $this->request(['navigation.manage'], $principal)->withHeader('Accept', 'text/html'),
            $this->neverHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function testFailsClosedWhenAdministratorRouteHasNoCapabilityPolicy(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
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
        string $method = 'GET',
    ): ServerRequestInterface {
        $route = new Route(
            '/administrator/navigation',
            $this->createStub(MiddlewareInterface::class),
            [$method],
            'administrator.navigation',
        );

        if ($configurePolicy) {
            $route->setOptions([
                AdministratorAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => $capabilities,
            ]);
        }

        return (new ServerRequestFactory())
            ->createServerRequest($method, 'https://kumwe.test/administrator/navigation')
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal);
    }

    private function session(AuthenticatedPrincipal $principal): AdministratorSession
    {
        return new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            $principal,
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        );
    }

    private function renderer(): AdministratorRenderer
    {
        $template = 'missing:{{ missing_capability }}'
            . '{% for item in administrator_navigation %}[{{ item.id }}]{% endfor %}';

        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader(['access-denied.twig' => $template])),
            new RecoveryAdministratorRenderer(
                new RecoveryAdministratorTwigEnvironment(new ArrayLoader()),
            ),
            AdministratorNavigationRegistry::core(),
        );
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
