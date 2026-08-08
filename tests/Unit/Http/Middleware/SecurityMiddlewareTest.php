<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Http\Middleware;

use Kumwe\CMS\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\CMS\Http\Middleware\BodyLimitMiddleware;
use Kumwe\CMS\Http\Middleware\ProblemDetailsMiddleware;
use Kumwe\CMS\Http\Middleware\RequestIdMiddleware;
use Kumwe\CMS\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\CMS\Http\Middleware\TrustedHostMiddleware;
use Kumwe\CMS\Http\Security\TrustedHostMatcher;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(BodyLimitMiddleware::class)]
#[CoversClass(ProblemDetailsMiddleware::class)]
#[CoversClass(RequestIdMiddleware::class)]
#[CoversClass(SecurityHeadersMiddleware::class)]
#[CoversClass(TrustedHostMiddleware::class)]
final class SecurityMiddlewareTest extends TestCase
{
    public function testTrustedHostPassesToHandler(): void
    {
        $middleware = new TrustedHostMiddleware(new TrustedHostMatcher(['kumwe.test']));
        $response = $middleware->process(
            $this->request()->withHeader('Host', 'kumwe.test'),
            $this->successfulHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testUntrustedHostIsRejected(): void
    {
        $middleware = new TrustedHostMiddleware(new TrustedHostMatcher(['kumwe.test']));
        $response = $middleware->process(
            $this->request()->withHeader('Host', 'attacker.test'),
            $this->successfulHandler(),
        );

        self::assertSame(400, $response->getStatusCode());
    }

    public function testOversizedBodyIsRejected(): void
    {
        $response = (new BodyLimitMiddleware(10))->process(
            $this->request()->withHeader('Content-Length', '11'),
            $this->successfulHandler(),
        );

        self::assertSame(413, $response->getStatusCode());
    }

    public function testRequestIdIsGeneratedAndReturned(): void
    {
        $response = (new RequestIdMiddleware())->process($this->request(), $this->successfulHandler());

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response->getHeaderLine('X-Request-ID'));
    }

    public function testProblemResponseDoesNotDiscloseProductionException(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('database-password-was-here');
            }
        };
        $response = (new ProblemDetailsMiddleware(new NullLogger(), false))->process($this->request(), $handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('database-password-was-here', (string) $response->getBody());
    }

    public function testHighImpactAuthenticationFailureIsAProtectedStepUpProblem(): void
    {
        $response = (new ProblemDetailsMiddleware(new NullLogger(), false))->process(
            $this->request(),
            $this->failingHandler(new HighImpactAuthenticationRequired('credential-was-here')),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('high-impact-authentication-required', (string) $response->getBody());
        self::assertStringNotContainsString('credential-was-here', (string) $response->getBody());
    }

    public function testAuthenticationThrottleIsAProtectedRateLimitProblem(): void
    {
        $response = (new ProblemDetailsMiddleware(new NullLogger(), false))->process(
            $this->request(),
            $this->failingHandler(new AuthenticationThrottled()),
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('authentication-throttled', (string) $response->getBody());
    }

    public function testSecurityHeadersAreApplied(): void
    {
        $response = (new SecurityHeadersMiddleware(true))->process($this->request(), $this->successfulHandler());

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertNotSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testSvgMediaReceivesAnIsolatedContentSecurityPolicy(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('<svg/>', 200, ['Content-Type' => 'image/svg+xml']);
            }
        };

        $response = (new SecurityHeadersMiddleware(true))->process($this->request(), $handler);

        self::assertSame(
            "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            $response->getHeaderLine('Content-Security-Policy'),
        );
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/');
    }

    private function successfulHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('', 204);
            }
        };
    }

    private function failingHandler(\Throwable $exception): RequestHandlerInterface
    {
        return new class ($exception) implements RequestHandlerInterface {
            public function __construct(private \Throwable $exception)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->exception;
            }
        };
    }
}
