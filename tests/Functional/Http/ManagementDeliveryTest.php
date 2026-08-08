<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Functional\Http;

use DateTimeImmutable;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorAuthorizationMiddleware;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorCsrfMiddleware;
use Kumwe\CMS\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(ContainerFactory::class)]
#[UsesClass(AdministratorAuthorizationMiddleware::class)]
#[UsesClass(AdministratorCsrfMiddleware::class)]
#[UsesClass(BearerAuthenticationMiddleware::class)]
final class ManagementDeliveryTest extends TestCase
{
    /** @var list<array{string, string}> */
    private const SCHEMA_MUTATION_CAPABILITIES = [
        ['/administrator/business-schema-plans/plan', 'business.schema.plan'],
        [
            '/administrator/business-schema-plans/018f22e2-7c8b-7ab0-8f3a-88e8026bb401/approve',
            'business.schema.approve',
        ],
        [
            '/administrator/business-schema-plans/018f22e2-7c8b-7ab0-8f3a-88e8026bb401/execute',
            'business.schema.execute',
        ],
        ['/administrator/business-schema-plans/recovery-evidence', 'business.schema.recover'],
        [
            '/administrator/business-schema-plans/018f22e2-7c8b-7ab0-8f3a-88e8026bb401/recover',
            'business.schema.recover',
        ],
        ['/administrator/business-schema-plans/purge', 'business.schema.destructive'],
    ];

    /** @var list<array{string, string}> */
    private const PROTECTED_ADMINISTRATOR_ROUTES = [
        ['GET', '/administrator/navigation'],
        ['GET', '/administrator/access'],
        ['GET', '/administrator/settings'],
        ['GET', '/administrator/automation'],
        ['GET', '/administrator/content-models'],
        ['GET', '/administrator/media'],
        ['GET', '/administrator/business-schema-plans'],
        ['POST', '/administrator/business-schema-plans/plan'],
        ['POST', '/administrator/business-schema-plans/018f22e2-7c8b-7ab0-8f3a-88e8026bb401/approve'],
        ['POST', '/administrator/business-schema-plans/018f22e2-7c8b-7ab0-8f3a-88e8026bb401/execute'],
        ['POST', '/administrator/business-schema-plans/recovery-evidence'],
        ['POST', '/administrator/business-schema-plans/018f22e2-7c8b-7ab0-8f3a-88e8026bb401/recover'],
        ['POST', '/administrator/business-schema-plans/purge'],
    ];

    /** @var list<array{string, string}> */
    private const PROTECTED_API_ROUTES = [
        ['GET', '/api/v1/menus'],
        ['POST', '/api/v1/menus'],
        ['GET', '/api/v1/menus/018f22e2-7c8b-7ab0-8f3a-88e8026bb401'],
        ['PATCH', '/api/v1/menus/018f22e2-7c8b-7ab0-8f3a-88e8026bb401'],
        ['DELETE', '/api/v1/menus/018f22e2-7c8b-7ab0-8f3a-88e8026bb401'],
        ['GET', '/api/v1/menus/018f22e2-7c8b-7ab0-8f3a-88e8026bb401/items'],
        ['POST', '/api/v1/menus/018f22e2-7c8b-7ab0-8f3a-88e8026bb401/items'],
        ['GET', '/api/v1/menu-items/018f22e2-7c8b-7ab0-8f3a-88e8026bb402'],
        ['PATCH', '/api/v1/menu-items/018f22e2-7c8b-7ab0-8f3a-88e8026bb402'],
        ['DELETE', '/api/v1/menu-items/018f22e2-7c8b-7ab0-8f3a-88e8026bb402'],
        ['GET', '/api/v1/users'],
        ['POST', '/api/v1/users'],
        ['PATCH', '/api/v1/users/018f22e2-7c8b-7ab0-8f3a-88e8026bb301'],
        ['GET', '/api/v1/roles'],
        ['POST', '/api/v1/roles'],
        [
            'PUT',
            '/api/v1/users/018f22e2-7c8b-7ab0-8f3a-88e8026bb301/roles/'
                . '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
        ],
        [
            'DELETE',
            '/api/v1/users/018f22e2-7c8b-7ab0-8f3a-88e8026bb301/roles/'
                . '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
        ],
        ['POST', '/api/v1/roles/018f22e2-7c8b-7ab0-8f3a-88e8026bb303/grants'],
        ['DELETE', '/api/v1/grants/018f22e2-7c8b-7ab0-8f3a-88e8026bb304'],
        ['POST', '/api/v1/tokens'],
        ['GET', '/api/v1/tokens'],
        ['DELETE', '/api/v1/tokens/018f22e2-7c8b-7ab0-8f3a-88e8026bb305'],
        ['POST', '/api/v1/tokens/018f22e2-7c8b-7ab0-8f3a-88e8026bb305/rotate'],
        ['DELETE', '/api/v1/users/018f22e2-7c8b-7ab0-8f3a-88e8026bb301/tokens'],
        ['GET', '/api/v1/extension-trust-keys'],
        ['POST', '/api/v1/extension-trust-keys'],
        ['POST', '/api/v1/extension-trust-keys/registry.primary/rotate'],
        ['DELETE', '/api/v1/extension-trust-keys/registry.primary'],
        ['GET', '/api/v1/schedules'],
        ['POST', '/api/v1/schedules'],
        ['GET', '/api/v1/schedules/018f22e2-7c8b-7ab0-8f3a-88e8026bb401'],
        ['PATCH', '/api/v1/schedules/018f22e2-7c8b-7ab0-8f3a-88e8026bb401'],
        ['DELETE', '/api/v1/schedules/018f22e2-7c8b-7ab0-8f3a-88e8026bb401'],
        ['GET', '/api/v1/jobs'],
        ['POST', '/api/v1/jobs/018f22e2-7c8b-7ab0-8f3a-88e8026bb402/retry'],
        ['POST', '/api/v1/jobs/018f22e2-7c8b-7ab0-8f3a-88e8026bb402/cancel'],
        ['GET', '/api/v1/content-types'],
        ['POST', '/api/v1/content-types'],
        ['GET', '/api/v1/content-types/018f22e2-7c8b-7ab0-8f3a-88e8026bb402'],
        ['PATCH', '/api/v1/content-types/018f22e2-7c8b-7ab0-8f3a-88e8026bb402'],
        ['GET', '/api/v1/workflows'],
        ['POST', '/api/v1/workflows'],
        ['GET', '/api/v1/workflows/018f22e2-7c8b-7ab0-8f3a-88e8026bb401'],
        ['PATCH', '/api/v1/workflows/018f22e2-7c8b-7ab0-8f3a-88e8026bb401'],
    ];

    public function testAdministratorManagementScreensAreRegisteredAndSessionProtected(): void
    {
        $application = $this->application();
        $factory = new ServerRequestFactory();

        foreach (self::PROTECTED_ADMINISTRATOR_ROUTES as [$method, $path]) {
            $response = $application->handle(
                $factory->createServerRequest($method, 'https://kumwe.test' . $path)
                    ->withHeader('Host', 'kumwe.test'),
            );

            if ($method === 'GET') {
                self::assertSame(
                    303,
                    $response->getStatusCode(),
                    sprintf('%s %s is not session protected.', $method, $path),
                );
                self::assertSame('/administrator/login', $response->getHeaderLine('Location'));
                continue;
            }

            self::assertSame(
                401,
                $response->getStatusCode(),
                sprintf('%s %s is not session protected.', $method, $path),
            );
            self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        }
    }

    public function testManagementApiRoutesAreRegisteredAndBearerProtected(): void
    {
        $application = $this->application();
        $factory = new ServerRequestFactory();

        foreach (self::PROTECTED_API_ROUTES as [$method, $path]) {
            $response = $application->handle(
                $factory->createServerRequest($method, 'https://kumwe.test' . $path)
                    ->withHeader('Host', 'kumwe.test'),
            );

            self::assertSame(
                401,
                $response->getStatusCode(),
                sprintf('%s %s is missing or bypasses bearer protection.', $method, $path),
            );
            self::assertStringContainsString('Bearer realm="kumwe-api"', $response->getHeaderLine('WWW-Authenticate'));
        }
    }

    public function testSchemaMutationsRequireTheirExactCapabilityAndValidCsrfToken(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        self::assertInstanceOf(Application::class, $container->get(Application::class));
        $router = $container->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $authorization = new AdministratorAuthorizationMiddleware();
        $csrf = new AdministratorCsrfMiddleware();
        $factory = new ServerRequestFactory();

        foreach (self::SCHEMA_MUTATION_CAPABILITIES as [$path, $capability]) {
            $base = $factory->createServerRequest('POST', 'https://kumwe.test' . $path)
                ->withHeader('Host', 'kumwe.test');
            $routeResult = $router->match($base);
            self::assertTrue($routeResult->isSuccess(), sprintf('POST %s is not registered.', $path));

            $denied = $authorization->process(
                $this->administratorRequest($base, $routeResult, ['administrator.access'], 'valid-csrf'),
                $this->csrfHandler($csrf),
            );
            self::assertSame(403, $denied->getStatusCode(), sprintf('%s did not require %s.', $path, $capability));
            self::assertStringContainsString($capability, (string) $denied->getBody());

            $invalidCsrf = $authorization->process(
                $this->administratorRequest($base, $routeResult, [$capability], 'wrong-csrf'),
                $this->csrfHandler($csrf),
            );
            self::assertSame(403, $invalidCsrf->getStatusCode(), sprintf('%s bypassed CSRF validation.', $path));
            self::assertStringContainsString('security token is invalid', (string) $invalidCsrf->getBody());

            $allowed = $authorization->process(
                $this->administratorRequest($base, $routeResult, [$capability], 'valid-csrf'),
                $this->csrfHandler($csrf),
            );
            self::assertSame(204, $allowed->getStatusCode(), sprintf('%s rejected its exact gates.', $path));
        }
    }

    private function application(): Application
    {
        $application = (new ContainerFactory())->create(Environment::fromGlobals())->get(Application::class);
        self::assertInstanceOf(Application::class, $application);

        return $application;
    }

    /** @param list<string> $capabilities */
    private function administratorRequest(
        ServerRequestInterface $request,
        RouteResult $routeResult,
        array $capabilities,
        string $providedCsrf,
    ): ServerRequestInterface {
        $principal = AuthorizationContext::principal($capabilities);

        return $request
            ->withParsedBody(['_csrf' => $providedCsrf])
            ->withAttribute(RouteResult::class, $routeResult)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
                $principal,
                'valid-csrf',
                new DateTimeImmutable('+1 hour'),
            ));
    }

    private function csrfHandler(AdministratorCsrfMiddleware $csrf): RequestHandlerInterface
    {
        return new class ($csrf) implements RequestHandlerInterface {
            public function __construct(private AdministratorCsrfMiddleware $csrf)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->csrf->process(
                    $request,
                    new class implements RequestHandlerInterface {
                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            return new TextResponse('', 204);
                        }
                    },
                );
            }
        };
    }
}
