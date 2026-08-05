<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DeliveryParityTest extends TestCase
{
    /** @var array<string, string> */
    private const ADMINISTRATOR_ROUTES = [
        'administrator.index' => 'content.read',
        'administrator.content.new' => 'content.create',
        'administrator.content.edit' => 'content.update',
        'administrator.content.create' => 'content.create',
        'administrator.content.update' => 'content.update',
        'administrator.content.transition' => 'content.read',
        'administrator.content.trash' => 'content.delete',
        'administrator.content.restore' => 'content.restore',
        'administrator.content-models' => 'content.read',
        'administrator.content-models.update' => 'content.update',
        'administrator.extensions' => 'extensions.manage',
        'administrator.extensions.install' => 'extensions.manage',
        'administrator.extensions.action' => 'extensions.manage',
        'administrator.settings' => 'settings.manage',
        'administrator.settings.update' => 'settings.manage',
        'administrator.navigation' => 'navigation.manage',
        'administrator.navigation.update' => 'navigation.manage',
        'administrator.access-control' => 'users.manage',
        'administrator.access-control.update' => 'users.manage',
        'administrator.automation' => 'automation.manage',
        'administrator.automation.update' => 'automation.manage',
    ];

    /** @var array<string, list<string>> */
    private const MANAGEMENT_API = [
        '/api/v1/menus' => ['get', 'post'],
        '/api/v1/menus/{id}' => ['get', 'patch', 'delete'],
        '/api/v1/menus/{menuId}/items' => ['get', 'post'],
        '/api/v1/menu-items/{id}' => ['get', 'patch', 'delete'],
        '/api/v1/users' => ['get', 'post'],
        '/api/v1/users/{id}' => ['patch'],
        '/api/v1/roles' => ['get', 'post'],
        '/api/v1/users/{id}/roles/{roleId}' => ['put', 'delete'],
        '/api/v1/roles/{id}/grants' => ['post'],
        '/api/v1/grants/{grantId}' => ['delete'],
        '/api/v1/tokens' => ['get', 'post'],
        '/api/v1/tokens/{tokenId}' => ['delete'],
        '/api/v1/tokens/{tokenId}/rotate' => ['post'],
        '/api/v1/users/{id}/tokens' => ['delete'],
        '/api/v1/users/{id}/tokens/emergency' => ['delete'],
        '/api/v1/extension-trust-keys' => ['get', 'post'],
        '/api/v1/extension-trust-keys/{keyId}/rotate' => ['post'],
        '/api/v1/extension-trust-keys/{keyId}' => ['delete'],
        '/api/v1/schedules' => ['get', 'post'],
        '/api/v1/schedules/{id}' => ['get', 'patch', 'delete'],
        '/api/v1/jobs' => ['get'],
        '/api/v1/jobs/{id}/retry' => ['post'],
        '/api/v1/jobs/{id}/cancel' => ['post'],
        '/api/v1/content-types' => ['get', 'post'],
        '/api/v1/content-types/{id}' => ['get', 'patch'],
        '/api/v1/workflows' => ['get', 'post'],
        '/api/v1/workflows/{id}' => ['get', 'patch'],
    ];

    public function testEveryCoreAdministratorRouteDeclaresItsCapabilityPolicy(): void
    {
        $source = $this->contents('src/Kernel/ContainerFactory.php');

        self::assertStringContainsString(
            '$application->pipe(AdministratorAuthorizationMiddleware::class);',
            $source,
        );

        foreach (self::ADMINISTRATOR_ROUTES as $routeName => $capability) {
            $offset = strpos($source, "'" . $routeName . "'");
            self::assertNotFalse($offset, sprintf('Administrator route %s is not registered.', $routeName));
            $start = max(0, $offset - 500);
            $snippet = substr($source, $start, 800);
            self::assertStringContainsString(
                'self::administratorRoute(',
                $snippet,
                sprintf('Administrator route %s bypasses capability policy registration.', $routeName),
            );
            self::assertStringContainsString(
                "'" . $capability . "'",
                $snippet,
                sprintf('Administrator route %s does not require %s.', $routeName, $capability),
            );
        }
    }

    /** @throws JsonException */
    public function testManagementRoutesAndOpenApiStayInParity(): void
    {
        $source = $this->contents('src/Kernel/ContainerFactory.php');
        $document = json_decode(
            $this->contents('api/openapi/kumwe-v1.json'),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($document);
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);

        foreach (self::MANAGEMENT_API as $path => $methods) {
            self::assertStringContainsString(
                "'" . $path . "'",
                $source,
                sprintf('Management API route %s is not registered.', $path),
            );
            self::assertArrayHasKey($path, $paths, sprintf('OpenAPI does not document %s.', $path));
            self::assertIsArray($paths[$path]);

            foreach ($methods as $method) {
                self::assertArrayHasKey(
                    $method,
                    $paths[$path],
                    sprintf('OpenAPI does not document %s %s.', strtoupper($method), $path),
                );
                $security = $paths[$path][$method]['security'] ?? null;
                self::assertIsArray($security);
                self::assertSame(
                    [['bearerAuth' => [], 'siteContext' => []]],
                    $security,
                    sprintf(
                        '%s %s does not require both bearer and explicit site binding.',
                        strtoupper($method),
                        $path,
                    ),
                );
            }
        }
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, sprintf('Could not read %s.', $path));

        return $contents;
    }
}
