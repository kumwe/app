<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use Kumwe\CMS\Application\Automation\AutomationManagementService;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\CMS\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\CMS\Infrastructure\Time\SystemClock;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Kumwe\CMS\Site\Infrastructure\Persistence\DoctrineSiteSettings;
use Kumwe\CMS\Workflow\Application\ContentTransitionAuthorizer;
use ReflectionClass;

final class McpHandlersFixture
{
    public static function create(McpCapabilityCatalog $catalog): KumweMcpHandlers
    {
        return new KumweMcpHandlers(
            $catalog,
            self::withoutConstructor(ContentService::class),
            self::withoutConstructor(NavigationService::class),
            self::withoutConstructor(AccessControlService::class),
            self::withoutConstructor(DoctrineSiteSettings::class),
            self::withoutConstructor(DoctrineExtensionManager::class),
            self::withoutConstructor(AutomationManagementService::class),
            self::withoutConstructor(ContentTransitionAuthorizer::class),
            self::withoutConstructor(McpMutationGuard::class),
            new SystemClock(),
            AuthorizationContext::gateway(),
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
