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
use Kumwe\CMS\Presentation\Application\StepUpAuthenticationRequired;
use Kumwe\CMS\Presentation\ThemeSurface;
use Kumwe\CMS\Site\Application\SiteSettings;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(KumweMcpHandlers::class)]
final class McpThemeIntegrationTest extends TestCase
{
    /**
     * Pins that a site-surface activation succeeds and reaches the manager with no step-up credential.
     *
     * The step-up position is asserted null rather than absent because a test double fills a defaulted
     * parameter in whether or not the caller supplied one. That the handler has no such parameter to
     * supply is proved separately, by `McpCatalogValidatorTest`, which reads the real signatures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMcpActivationReachesTheManagerWithoutAStepUpCredential(): void
    {
        $received = [];
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->willReturnCallback(
            static function (mixed ...$arguments) use (&$received): array {
                $received = $arguments;

                return ['identifier' => 'acme/corporate', 'status' => 'active'];
            },
        );
        $context = AuthorizationContext::human(['extensions.manage', 'themes.site.manage']);

        $result = $this->handlers($extensions)->forContext($context)->activateExtension(
            'theme-activation-0001',
            'acme/corporate',
            'site',
        );

        self::assertSame('active', $result['status']);
        self::assertSame('acme/corporate', $received[0]);
        self::assertInstanceOf(ExecutionContext::class, $received[1]);
        self::assertSame(ThemeSurface::Site, $received[2]);
        self::assertNull($received[3] ?? null);
    }

    /**
     * Pins that an administrator-surface activation fails closed instead of being offered a password.
     *
     * The guard refuses an activation carrying no step-up proof, and a machine caller can never carry
     * one. This asserts the refusal travels out of the machine surface unchanged, rather than being
     * converted into an authorization denial or answered by re-prompting for a credential.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMcpAdministratorThemeActivationFailsClosedWithoutAStepUpRoute(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->willThrowException(
            new StepUpAuthenticationRequired(
                'Administrator theme activation requires current-password step-up authentication.',
            ),
        );
        $context = AuthorizationContext::human(['extensions.manage', 'themes.administrator.manage']);
        $this->expectException(StepUpAuthenticationRequired::class);

        $this->handlers($extensions)->forContext($context)->activateExtension(
            'theme-activation-0002',
            'acme/corporate',
            'administrator',
        );
    }

    /**
     * Pins that a disable still enforces the surface capability the extension's live bindings imply.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Pins that an uninstall reaches the manager with the identifier and context and no credential.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMcpUninstallReachesTheManagerWithoutAStepUpCredential(): void
    {
        $received = [];
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('uninstall')->willReturnCallback(
            static function (mixed ...$arguments) use (&$received): void {
                $received = $arguments;
            },
        );
        $context = AuthorizationContext::human(['extensions.manage']);

        $result = $this->handlers($extensions)->forContext($context)->uninstallExtension(
            'theme-uninstall-0001',
            'acme/corporate',
        );

        self::assertTrue($result['uninstalled']);
        self::assertSame('acme/corporate', $received[0]);
        self::assertInstanceOf(ExecutionContext::class, $received[1]);
        self::assertNull($received[2] ?? null);
    }

    /**
     * Build MCP handlers over an in-memory schema with the extension manager under test injected.
     *
     * @param   ExtensionManager  $extensions  Manager the lifecycle tools are expected to reach.
     *
     * @return  KumweMcpHandlers  Handlers ready to be rebound to an execution context.
     *
     * @since   2.0.0
     */
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
