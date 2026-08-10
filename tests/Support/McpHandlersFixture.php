<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use Kumwe\CMS\Application\Automation\AutomationManagementService;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Infrastructure\Administration\DoctrineAdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\CMS\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\CMS\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\CMS\Infrastructure\Time\SystemClock;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Kumwe\CMS\Site\Infrastructure\Persistence\DoctrineSiteSettings;
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
            self::withoutConstructor(RedisLockedExtensionManager::class),
            self::withoutConstructor(TrustStore::class),
            self::withoutConstructor(DoctrineAdministratorIdentityGateway::class),
            self::withoutConstructor(AutomationManagementService::class),
            self::withoutConstructor(BusinessDefinitionService::class),
            self::withoutConstructor(BusinessSchemaService::class),
            self::withoutConstructor(BusinessMcpHandlers::class),
            self::withoutConstructor(McpMutationGuard::class),
            new SystemClock(),
            AuthorizationContext::gateway(),
            self::withoutConstructor(TokenRotationPreauthorizer::class),
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
