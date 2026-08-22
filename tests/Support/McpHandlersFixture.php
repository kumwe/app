<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\App\Infrastructure\Mcp\ReportMcpHandlers;
use Kumwe\App\Infrastructure\Time\SystemClock;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Site\Infrastructure\Persistence\DoctrineSiteSettings;
use ReflectionClass;

final class McpHandlersFixture
{
    public static function create(
        McpCapabilityCatalog $catalog,
        ?ExtensionExecutionGate $extensionRuntime = null,
    ): KumweMcpHandlers {
        return new KumweMcpHandlers(
            $catalog,
            self::withoutConstructor(ContentService::class),
            self::withoutConstructor(NavigationService::class),
            self::withoutConstructor(AccessControlService::class),
            self::withoutConstructor(DoctrineSiteSettings::class),
            self::withoutConstructor(RedisLockedExtensionManager::class),
            self::withoutConstructor(TrustStore::class),
            self::withoutConstructor(AutomationManagementService::class),
            self::withoutConstructor(BusinessDefinitionService::class),
            self::withoutConstructor(BusinessSchemaService::class),
            self::withoutConstructor(BusinessMcpHandlers::class),
            self::withoutConstructor(ReportMcpHandlers::class),
            self::withoutConstructor(McpMutationGuard::class),
            new SystemClock(),
            AuthorizationContext::gateway(),
            extensionRuntime: $extensionRuntime,
        );
    }

    /**
     * @template T of object
     *
     * @param   class-string<T>  $class
     *
     * @return  T
     */
    private static function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
