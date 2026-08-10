<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Presentation;

use Doctrine\DBAL\DriverManager;
use Kumwe\CMS\Application\Automation\AutomationManagementService;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\CMS\Extension\Application\Trust\TrustKeySignatureVerifier;
use Kumwe\CMS\Extension\Application\Trust\TrustRuntimeInvalidator;
use Kumwe\CMS\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\CMS\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\CMS\Infrastructure\Mcp\ReportMcpHandlers;
use Kumwe\CMS\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\AuthorizationRecoveryIntegrationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\JobRecoveryMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\IsolateThemeSurfacesMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\TokenAndTrustLifecycleMigration;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Time\SystemClock;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Kumwe\CMS\Presentation\ThemeSurface;
use Kumwe\CMS\Site\Application\SiteSettings;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(KumweMcpHandlers::class)]
final class McpThemeIntegrationTest extends TestCase
{
    public function testMcpActivationCarriesAuthorizedAdministratorSurfaceAndStepUp(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->with(
            'acme/corporate',
            self::isInstanceOf(ExecutionContext::class),
            ThemeSurface::Administrator,
            'correct horse battery staple',
        )->willReturn(['identifier' => 'acme/corporate', 'status' => 'active']);
        $context = AuthorizationContext::human(
            ['extensions.manage', 'themes.administrator.manage'],
        );

        $result = $this->handlers($extensions)->forContext($context)->activateExtension(
            'theme-activation-0001',
            'acme/corporate',
            'administrator',
            'correct horse battery staple',
        );

        self::assertSame('active', $result['status']);
    }

    public function testMcpDisableRejectsMissingActiveThemeCapability(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('disable')->willThrowException(
            new InsufficientCapability('themes.site.manage'),
        );
        $context = AuthorizationContext::human(['extensions.manage']);
        $this->expectException(InsufficientCapability::class);

        $this->handlers($extensions)->forContext($context)->disableExtension(
            'theme-disable-000001',
            'acme/corporate',
        );
    }

    public function testMcpUninstallForwardsAdministratorStepUp(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('uninstall')->with(
            'acme/corporate',
            self::isInstanceOf(ExecutionContext::class),
            'correct horse battery staple',
        );
        $context = AuthorizationContext::human(
            ['extensions.manage', 'themes.administrator.manage'],
        );

        $result = $this->handlers($extensions)->forContext($context)->uninstallExtension(
            'theme-uninstall-0001',
            'acme/corporate',
            'correct horse battery staple',
        );

        self::assertTrue($result['uninstalled']);
    }

    private function handlers(ExtensionManager $extensions): KumweMcpHandlers
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        (new CoreSchemaMigration($tables))->up($database);
        (new ApplicationAuthorizationMigration($tables))->up($database);
        (new JobRecoveryMigration($tables))->up($database);
        (new AuthorizationRecoveryIntegrationMigration($tables))->up($database);
        (new TokenAndTrustLifecycleMigration($tables, sys_get_temp_dir()))->up($database);
        (new IsolateThemeSurfacesMigration($tables))->up($database);
        $clock = new SystemClock();
        $transactions = new DoctrineTransactionManager($database);
        $repository = $this->createStub(TrustStoreRepository::class);
        $repository->method('synchronizedLifecycle')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $trust = new TrustStore(
            $repository,
            $this->createStub(TrustKeySignatureVerifier::class),
            $this->createStub(ExtensionArtifactVerifier::class),
            $this->createStub(TrustRuntimeInvalidator::class),
            $transactions,
            $this->createStub(AuditRecorder::class),
            $clock,
            AuthorizationContext::gateway(),
            true,
        );

        return new KumweMcpHandlers(
            new McpCapabilityCatalog(),
            $this->withoutConstructor(ContentService::class),
            $this->withoutConstructor(NavigationService::class),
            $this->withoutConstructor(AccessControlService::class),
            $this->createStub(SiteSettings::class),
            $extensions,
            $trust,
            $this->createStub(AdministratorIdentityGateway::class),
            $this->withoutConstructor(AutomationManagementService::class),
            $this->withoutConstructor(BusinessDefinitionService::class),
            $this->withoutConstructor(BusinessSchemaService::class),
            $this->withoutConstructor(BusinessMcpHandlers::class),
            $this->withoutConstructor(ReportMcpHandlers::class),
            new McpMutationGuard($database, $tables, $clock, $transactions),
            $clock,
            AuthorizationContext::gateway(),
            $this->withoutConstructor(TokenRotationPreauthorizer::class),
        );
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
