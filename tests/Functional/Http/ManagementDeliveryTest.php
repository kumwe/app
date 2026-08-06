<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Functional\Http;

use Kumwe\CMS\Administrator\Http\Middleware\AdministratorAuthorizationMiddleware;
use Kumwe\CMS\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContainerFactory::class)]
#[UsesClass(AdministratorAuthorizationMiddleware::class)]
#[UsesClass(BearerAuthenticationMiddleware::class)]
final class ManagementDeliveryTest extends TestCase
{
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

        foreach (
            [
            '/administrator/navigation',
            '/administrator/access',
            '/administrator/settings',
            '/administrator/automation',
            '/administrator/content-models',
            '/administrator/media',
            ] as $path
        ) {
            $response = $application->handle(
                $factory->createServerRequest('GET', 'https://kumwe.test' . $path)
                    ->withHeader('Host', 'kumwe.test'),
            );

            self::assertSame(303, $response->getStatusCode(), sprintf('%s is not session protected.', $path));
            self::assertSame('/administrator/login', $response->getHeaderLine('Location'));
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

    private function application(): Application
    {
        $application = (new ContainerFactory())->create(Environment::fromGlobals())->get(Application::class);
        self::assertInstanceOf(Application::class, $application);

        return $application;
    }
}
