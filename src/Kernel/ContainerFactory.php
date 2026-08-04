<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel;

use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Event\Dispatcher;
use Joomla\Event\DispatcherInterface;
use Kumwe\CMS\Application\Automation\Job\PostgreSqlJobQueue;
use Kumwe\CMS\Application\Automation\Job\PostgreSqlScheduler;
use Kumwe\CMS\Application\Automation\Job\PurgeAdministratorSessionsHandler;
use Kumwe\CMS\Application\Automation\Job\RebuildExtensionMapHandler;
use Kumwe\CMS\Application\Automation\Job\ScheduleRepository;
use Kumwe\CMS\Application\Automation\Job\TransitionContentHandler;
use Kumwe\CMS\Application\Automation\JobHandlerRegistry;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\Application\Automation\Scheduler;
use Kumwe\CMS\Application\Automation\Worker;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorContentEditorHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorCreateContentHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorDashboardHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorExtensionActionHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorExtensionsHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorLoginHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorLogoutHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorRestoreContentHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorSettingsHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorTransitionContentHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorTrashContentHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorUpdateContentHandler;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorCsrfMiddleware;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Infrastructure\Persistence\PostgreSqlAuditRecorder;
use Kumwe\CMS\Content\Application\ContentRepository;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Infrastructure\Persistence\PostgreSqlContentRepository;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Package\ArchiveReader;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Infrastructure\Package\ZipArchiveReader;
use Kumwe\CMS\Extension\Infrastructure\PostgreSqlExtensionManager;
use Kumwe\CMS\Extension\Runtime\ActiveExtensionSet;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeLoader;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Delivery\Console\Command\CreateAccessTokenCommand;
use Kumwe\CMS\Delivery\Console\Command\CreateAdministratorCommand;
use Kumwe\CMS\Delivery\Console\Command\CreateScheduleCommand;
use Kumwe\CMS\Delivery\Console\Command\ActivateExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\DisableExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\HealthCheckCommand;
use Kumwe\CMS\Delivery\Console\Command\InstallExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\ListExtensionsCommand;
use Kumwe\CMS\Delivery\Console\Command\ListSchedulesCommand;
use Kumwe\CMS\Delivery\Console\Command\McpServeCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrateCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrationStatusCommand;
use Kumwe\CMS\Delivery\Console\Command\QueueWorkCommand;
use Kumwe\CMS\Delivery\Console\Command\ScheduleRunCommand;
use Kumwe\CMS\Delivery\Console\Command\UninstallExtensionCommand;
use Kumwe\CMS\Delivery\Console\ConsoleApplication;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Delivery\Console\StreamOutput;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\PersistentIdempotencyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentApiResponder;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentCollectionHandler;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentItemHandler;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentRestoreHandler;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentTransitionHandler;
use Kumwe\CMS\Delivery\Http\Api\Plan\PlanPreviewHandler;
use Kumwe\CMS\Delivery\Http\Api\Plan\SafePlanFactory;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Delivery\Http\Mcp\McpHttpHandler;
use Kumwe\CMS\Http\Handler\ApiIndexHandler;
use Kumwe\CMS\Http\Handler\HomePageHandler;
use Kumwe\CMS\Http\Handler\LivenessHandler;
use Kumwe\CMS\Http\Handler\NotFoundHandler;
use Kumwe\CMS\Http\Handler\PublishedContentHandler;
use Kumwe\CMS\Http\Handler\ReadinessHandler;
use Kumwe\CMS\Http\Middleware\BodyLimitMiddleware;
use Kumwe\CMS\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\CMS\Http\Middleware\ProblemDetailsMiddleware;
use Kumwe\CMS\Http\Middleware\RequestIdMiddleware;
use Kumwe\CMS\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\CMS\Http\Middleware\TrustedHostMiddleware;
use Kumwe\CMS\Http\Security\TrustedHostMatcher;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Identity\Infrastructure\Administration\PostgreSqlAdministratorIdentityGateway;
use Kumwe\CMS\Identity\Infrastructure\Administration\PostgreSqlAdministratorSessionStore;
use Kumwe\CMS\Identity\Infrastructure\Authentication\PostgreSqlAccessTokenVerifier;
use Kumwe\CMS\Identity\Infrastructure\Security\NativePasswordHasher;
use Kumwe\CMS\Infrastructure\Persistence\JoomlaTransactionManager;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationLock;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRepository;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\CMS\Infrastructure\Persistence\Migration\PostgreSqlMigrationLock;
use Kumwe\CMS\Infrastructure\Persistence\Migration\PostgreSqlMigrationRepository;
use Kumwe\CMS\Infrastructure\Persistence\Migration\SchemaMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\Version202608040001CreateSystemTables;
use Kumwe\CMS\Infrastructure\Persistence\PostgreSqlDatabaseFactory;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\CMS\Infrastructure\Time\SystemClock;
use Kumwe\CMS\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\CMS\Kernel\Configuration\ConfigurationFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Site\Application\SiteSettings;
use Kumwe\CMS\Site\Infrastructure\Persistence\PostgreSqlSiteSettings;
use Kumwe\CMS\Workflow\Domain\Workflow;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\Emitter\EmitterStack;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\Stratigility\MiddlewarePipe;
use Laminas\Stratigility\MiddlewarePipeInterface;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Mezzio\Application;
use Mezzio\MiddlewareContainer;
use Mezzio\MiddlewareFactory;
use Mezzio\MiddlewareFactoryInterface;
use Mezzio\Response\ServerRequestErrorResponseGenerator;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\ImplicitHeadMiddleware;
use Mezzio\Router\Middleware\ImplicitOptionsMiddleware;
use Mezzio\Router\Middleware\MethodNotAllowedMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Mezzio\Router\RouteCollector;
use Mezzio\Router\RouteCollectorInterface;
use Mezzio\Router\RouterInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

final class ContainerFactory
{
    public function create(Environment $environment): Container
    {
        $configuration = (new ConfigurationFactory())->create($environment);
        $container = new Container();
        $root = dirname(__DIR__, 2);

        $container->share(Container::class, $container, true);
        $container->alias(ContainerInterface::class, Container::class);
        $container->share(ApplicationConfiguration::class, $configuration, true);
        $container->share(ClockInterface::class, new SystemClock(), true);
        $container->share(Dispatcher::class, new Dispatcher(), true);
        $container->alias(DispatcherInterface::class, Dispatcher::class);
        $container->share('config', [
            'debug' => $configuration->debug,
            'router' => [
                'detect_duplicates' => true,
                'fastroute' => [
                    'cache_enabled' => $configuration->isProduction(),
                    'cache_file' => $root . '/storage/cache/routes.php',
                ],
            ],
        ], true);

        $this->registerLogging($container, $configuration);
        $this->registerPersistence($container, $configuration, $root);
        $this->registerExtensions($container, $configuration, $root);
        $this->registerMcp($container, $root);
        $this->registerHttp($container, $configuration, $root);
        $this->registerConsole($container);

        return $container;
    }

    private function registerLogging(Container $container, ApplicationConfiguration $configuration): void
    {
        $container->share(Logger::class, static function () use ($configuration): Logger {
            $logger = new Logger('kumwe');
            $logger->pushHandler(new StreamHandler(
                'php://stderr',
                $configuration->debug ? Level::Debug : Level::Info,
            ));

            return $logger;
        }, true);
        $container->alias(LoggerInterface::class, Logger::class);
    }

    private function registerPersistence(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
    ): void {
        $databaseConfiguration = $configuration->database;
        $container->share(DatabaseInterface::class, static fn (): DatabaseInterface =>
            (new PostgreSqlDatabaseFactory($databaseConfiguration))->create(), true);
        $container->share(TransactionManager::class, static fn (Container $container): TransactionManager =>
            new JoomlaTransactionManager(self::service($container, DatabaseInterface::class)), true);
        $container->share(PasswordHasher::class, new NativePasswordHasher(), true);
        $container->share(Workflow::class, new Workflow(), true);
        $container->share(MigrationRepository::class, static fn (Container $container): MigrationRepository =>
            new PostgreSqlMigrationRepository(
                self::service($container, DatabaseInterface::class),
                $databaseConfiguration->schema,
            ), true);
        $container->share(MigrationLock::class, static fn (Container $container): MigrationLock =>
            new PostgreSqlMigrationLock(self::service($container, DatabaseInterface::class)), true);
        $container->share(AccessTokenVerifier::class, static fn (Container $container): AccessTokenVerifier =>
            new PostgreSqlAccessTokenVerifier(
                self::service($container, DatabaseInterface::class),
                $databaseConfiguration->schema,
            ), true);
        $container->share(AdministratorIdentityGateway::class, static fn (
            Container $container,
        ): AdministratorIdentityGateway => new PostgreSqlAdministratorIdentityGateway(
            self::service($container, DatabaseInterface::class),
            $databaseConfiguration->schema,
            self::service($container, PasswordHasher::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            $configuration->secret,
        ), true);
        $container->share(AdministratorSessionStore::class, static fn (
            Container $container,
        ): AdministratorSessionStore => new PostgreSqlAdministratorSessionStore(
            self::service($container, DatabaseInterface::class),
            $databaseConfiguration->schema,
            self::service($container, ClockInterface::class),
            $configuration->secret,
            $configuration->administratorSessionSeconds,
        ), true);
        $container->share(AuditRecorder::class, static fn (Container $container): AuditRecorder =>
            new PostgreSqlAuditRecorder(
                self::service($container, DatabaseInterface::class),
                $databaseConfiguration->schema,
            ), true);
        $container->share(ContentRepository::class, static fn (Container $container): ContentRepository =>
            new PostgreSqlContentRepository(
                self::service($container, DatabaseInterface::class),
                $databaseConfiguration->schema,
            ), true);
        $container->share(ContentService::class, static fn (Container $container): ContentService =>
            new ContentService(
                self::service($container, ContentRepository::class),
                self::service($container, AuditRecorder::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, Workflow::class),
            ), true);
        $container->share(SiteSettings::class, static fn (Container $container): SiteSettings =>
            new PostgreSqlSiteSettings(
                self::service($container, DatabaseInterface::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                $databaseConfiguration->schema,
            ), true);
        $container->share(JobQueue::class, static fn (Container $container): JobQueue =>
            new PostgreSqlJobQueue(
                self::service($container, DatabaseInterface::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                $databaseConfiguration->schema,
                $configuration->release,
            ), true);
        $container->share(PostgreSqlScheduler::class, static fn (
            Container $container,
        ): PostgreSqlScheduler => new PostgreSqlScheduler(
            self::service($container, DatabaseInterface::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            $databaseConfiguration->schema,
        ), true);
        $container->alias(Scheduler::class, PostgreSqlScheduler::class);
        $container->alias(ScheduleRepository::class, PostgreSqlScheduler::class);
        $container->share(MigrationRunner::class, static fn (Container $container): MigrationRunner =>
            new MigrationRunner(
                database: self::service($container, DatabaseInterface::class),
                repository: self::service($container, MigrationRepository::class),
                lock: self::service($container, MigrationLock::class),
                transactions: self::service($container, TransactionManager::class),
                migrations: [
                    new Version202608040001CreateSystemTables($databaseConfiguration->schema),
                    SchemaMigration::fromFile(
                        '20260804000300_create_identity_and_audit',
                        $databaseConfiguration->schema,
                        $root . '/database/schema/phase3.sql',
                    ),
                    SchemaMigration::fromFile(
                        '20260804000400_create_content_workflow_and_navigation',
                        $databaseConfiguration->schema,
                        $root . '/database/schema/phase4.sql',
                    ),
                    SchemaMigration::fromFile(
                        '20260804000500_create_extension_platform',
                        $databaseConfiguration->schema,
                        $root . '/database/schema/phase5.sql',
                    ),
                    SchemaMigration::fromFile(
                        '20260804000600_create_presentation_platform',
                        $databaseConfiguration->schema,
                        $root . '/database/schema/phase6.sql',
                    ),
                    SchemaMigration::fromFile(
                        '20260804000700_create_automation_platform',
                        $databaseConfiguration->schema,
                        $root . '/database/schema/phase7.sql',
                    ),
                    SchemaMigration::fromFile(
                        '20260804000800_create_application_runtime',
                        $databaseConfiguration->schema,
                        $root . '/database/schema/application.sql',
                    ),
                ],
            ), true);
        $container->share(ReadinessProbe::class, static fn (Container $container): ReadinessProbe =>
            new ReadinessProbe(
                database: self::service($container, DatabaseInterface::class),
                logger: self::service($container, LoggerInterface::class),
                schema: $databaseConfiguration->schema,
                requiredMigration: '20260804000800_create_application_runtime',
            ), true);
    }

    private function registerHttp(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
    ): void {
        $container->share(ResponseFactoryInterface::class, new ResponseFactory(), true);
        $container->share(StreamFactoryInterface::class, new StreamFactory(), true);
        $container->share(FilesystemLoader::class, static function (
            Container $container,
        ) use ($root): FilesystemLoader {
            $loader = new FilesystemLoader();

            foreach (self::service($container, ActiveExtensionSet::class)->templatePaths() as $path) {
                $loader->addPath($path);
            }

            $loader->addPath($root . '/templates');

            return $loader;
        }, true);
        $container->share(TwigEnvironment::class, static fn (Container $container): TwigEnvironment =>
            new TwigEnvironment(
                self::service($container, FilesystemLoader::class),
                [
                    'autoescape' => 'html',
                    'cache' => $configuration->isProduction() ? $root . '/storage/cache/twig' : false,
                    'strict_variables' => true,
                ],
            ), true);
        $container->share(AdministratorRenderer::class, static fn (Container $container): AdministratorRenderer =>
            new AdministratorRenderer(self::service($container, TwigEnvironment::class)), true);
        $container->share(RouterInterface::class, static fn (): RouterInterface =>
            new FastRouteRouter(null, null, [
                'cache_enabled' => $configuration->isProduction(),
                'cache_file' => $root . '/storage/cache/routes.php',
            ]), true);
        $container->share(RouteCollector::class, static fn (Container $container): RouteCollector =>
            new RouteCollector(self::service($container, RouterInterface::class), true), true);
        $container->alias(RouteCollectorInterface::class, RouteCollector::class);
        $container->share(MiddlewareContainer::class, static fn (Container $container): MiddlewareContainer =>
            new MiddlewareContainer($container), true);
        $container->share(MiddlewareFactory::class, static fn (Container $container): MiddlewareFactory =>
            new MiddlewareFactory(self::service($container, MiddlewareContainer::class)), true);
        $container->alias(MiddlewareFactoryInterface::class, MiddlewareFactory::class);
        $container->share(MiddlewarePipeInterface::class, new MiddlewarePipe(), true);
        $container->share(EmitterInterface::class, static function (): EmitterInterface {
            $emitter = new EmitterStack();
            $emitter->push(new SapiEmitter());

            return $emitter;
        }, true);
        $container->share(ServerRequestErrorResponseGenerator::class, static function (
            Container $container,
        ): ServerRequestErrorResponseGenerator {
            return new ServerRequestErrorResponseGenerator(
                self::service($container, ResponseFactoryInterface::class),
                false,
            );
        }, true);
        $container->share(RequestHandlerRunnerInterface::class, static function (
            Container $container,
        ): RequestHandlerRunnerInterface {
            return new RequestHandlerRunner(
                self::service($container, MiddlewarePipeInterface::class),
                self::service($container, EmitterInterface::class),
                static fn () => ServerRequestFactory::fromGlobals(),
                self::service($container, ServerRequestErrorResponseGenerator::class),
            );
        }, true);

        $this->registerMiddleware($container, $configuration);
        $this->registerHandlers($container);
        $container->share(Application::class, function (Container $container): Application {
            $application = new Application(
                self::service($container, MiddlewareFactoryInterface::class),
                self::service($container, MiddlewarePipeInterface::class),
                self::service($container, RouteCollectorInterface::class),
                self::service($container, RequestHandlerRunnerInterface::class),
            );
            $this->configureApplication($application, $container);

            return $application;
        }, true);
    }

    private function registerExtensions(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
    ): void {
        $mapFile = $root . '/storage/cache/extensions.json';
        $extensionRoot = $root . '/extensions';
        $schema = $configuration->database->schema;
        $container->share(ArchiveReader::class, new ZipArchiveReader(), true);
        $container->share(PackageSafetyPolicy::class, new PackageSafetyPolicy(), true);
        $container->share(ExtensionRuntimeMapCompiler::class, static fn (
            Container $container,
        ): ExtensionRuntimeMapCompiler => new ExtensionRuntimeMapCompiler(
            self::service($container, DatabaseInterface::class),
            $schema,
            $mapFile,
        ), true);
        $container->share(ExtensionManager::class, static fn (Container $container): ExtensionManager =>
            new PostgreSqlExtensionManager(
                self::service($container, DatabaseInterface::class),
                $schema,
                $extensionRoot,
                self::service($container, ArchiveReader::class),
                self::service($container, PackageSafetyPolicy::class),
                self::service($container, ExtensionRuntimeMapCompiler::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                $configuration->allowUnsignedLocalExtensions,
            ), true);
        $active = (new ExtensionRuntimeLoader($mapFile, $extensionRoot))->load($container);
        $container->share(ActiveExtensionSet::class, $active, true);
    }

    private function registerMiddleware(Container $container, ApplicationConfiguration $configuration): void
    {
        $container->share(RequestIdMiddleware::class, new RequestIdMiddleware(), true);
        $container->share(ProblemDetailsMiddleware::class, static function (
            Container $container,
        ) use ($configuration): ProblemDetailsMiddleware {
            return new ProblemDetailsMiddleware(
                self::service($container, LoggerInterface::class),
                $configuration->debug,
            );
        }, true);
        $container->share(TrustedHostMiddleware::class, new TrustedHostMiddleware(
            new TrustedHostMatcher($configuration->trustedHosts),
        ), true);
        $container->share(BodyLimitMiddleware::class, new BodyLimitMiddleware($configuration->maxBodyBytes), true);
        $container->share(ProblemDetailsResponseFactory::class, new ProblemDetailsResponseFactory(), true);
        $container->share(RequireIdempotencyKeyMiddleware::class, static function (
            Container $container,
        ): RequireIdempotencyKeyMiddleware {
            return new RequireIdempotencyKeyMiddleware(
                self::service($container, ProblemDetailsResponseFactory::class),
            );
        }, true);
        $container->share(PersistentIdempotencyMiddleware::class, static fn (
            Container $container,
        ): PersistentIdempotencyMiddleware => new PersistentIdempotencyMiddleware(
            self::service($container, DatabaseInterface::class),
            self::service($container, ClockInterface::class),
            self::service($container, ProblemDetailsResponseFactory::class),
            $configuration->database->schema,
        ), true);
        $container->share(RequireIfMatchMiddleware::class, static function (
            Container $container,
        ): RequireIfMatchMiddleware {
            return new RequireIfMatchMiddleware(
                self::service($container, ProblemDetailsResponseFactory::class),
            );
        }, true);
        $container->share(AdministratorSessionMiddleware::class, static fn (
            Container $container,
        ): AdministratorSessionMiddleware => new AdministratorSessionMiddleware(
            self::service($container, AdministratorSessionStore::class),
        ), true);
        $container->share(AdministratorCsrfMiddleware::class, new AdministratorCsrfMiddleware(), true);
        $container->share(BearerAuthenticationMiddleware::class, static function (
            Container $container,
        ): BearerAuthenticationMiddleware {
            return new BearerAuthenticationMiddleware(self::service($container, AccessTokenVerifier::class));
        }, true);
        $container->share(SecurityHeadersMiddleware::class, new SecurityHeadersMiddleware(
            $configuration->isProduction(),
        ), true);
        $container->share(RouteMiddleware::class, static fn (Container $container): RouteMiddleware =>
            new RouteMiddleware(self::service($container, RouterInterface::class)), true);
        $container->share(ImplicitHeadMiddleware::class, static fn (Container $container): ImplicitHeadMiddleware =>
            new ImplicitHeadMiddleware(
                self::service($container, RouterInterface::class),
                self::service($container, StreamFactoryInterface::class),
            ), true);
        $container->share(ImplicitOptionsMiddleware::class, static function (
            Container $container,
        ): ImplicitOptionsMiddleware {
            return new ImplicitOptionsMiddleware(
                self::service($container, ResponseFactoryInterface::class),
            );
        }, true);
        $container->share(MethodNotAllowedMiddleware::class, static function (
            Container $container,
        ): MethodNotAllowedMiddleware {
            return new MethodNotAllowedMiddleware(
                self::service($container, ResponseFactoryInterface::class),
            );
        }, true);
        $container->share(DispatchMiddleware::class, new DispatchMiddleware(), true);
    }

    private function registerHandlers(Container $container): void
    {
        $container->share(HomePageHandler::class, static fn (Container $container): HomePageHandler =>
            new HomePageHandler(
                self::service($container, ContentService::class),
                self::service($container, SiteSettings::class),
                self::service($container, TwigEnvironment::class),
            ), true);
        $container->share(LivenessHandler::class, new LivenessHandler(), true);
        $container->share(ApiIndexHandler::class, new ApiIndexHandler(), true);
        $container->share(NotFoundHandler::class, new NotFoundHandler(), true);
        $container->share(ReadinessHandler::class, static fn (Container $container): ReadinessHandler =>
            new ReadinessHandler(self::service($container, ReadinessProbe::class)), true);
        $container->share(SafePlanFactory::class, static fn (Container $container): SafePlanFactory =>
            new SafePlanFactory(self::service($container, ClockInterface::class)), true);
        $container->share(PlanPreviewHandler::class, static fn (Container $container): PlanPreviewHandler =>
            new PlanPreviewHandler(
                self::service($container, SafePlanFactory::class),
                self::service($container, ProblemDetailsResponseFactory::class),
            ), true);
        $container->share(ContentApiResponder::class, static fn (Container $container): ContentApiResponder =>
            new ContentApiResponder(self::service($container, ProblemDetailsResponseFactory::class)), true);
        $container->share(ContentCollectionHandler::class, static fn (
            Container $container,
        ): ContentCollectionHandler => new ContentCollectionHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentItemHandler::class, static fn (
            Container $container,
        ): ContentItemHandler => new ContentItemHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentTransitionHandler::class, static fn (
            Container $container,
        ): ContentTransitionHandler => new ContentTransitionHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentRestoreHandler::class, static fn (
            Container $container,
        ): ContentRestoreHandler => new ContentRestoreHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(PublishedContentHandler::class, static fn (
            Container $container,
        ): PublishedContentHandler => new PublishedContentHandler(
            self::service($container, ContentService::class),
            self::service($container, TwigEnvironment::class),
        ), true);
        $configuration = self::service($container, ApplicationConfiguration::class);
        $secureCookie = parse_url($configuration->baseUrl, PHP_URL_SCHEME) === 'https';
        $container->share(AdministratorLoginHandler::class, static fn (
            Container $container,
        ): AdministratorLoginHandler => new AdministratorLoginHandler(
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, AdministratorSessionStore::class),
            self::service($container, AdministratorRenderer::class),
            $secureCookie,
            $configuration->administratorSessionSeconds,
        ), true);
        $container->share(AdministratorLogoutHandler::class, static fn (
            Container $container,
        ): AdministratorLogoutHandler => new AdministratorLogoutHandler(
            self::service($container, AdministratorSessionStore::class),
            $secureCookie,
        ), true);
        $container->share(AdministratorDashboardHandler::class, static fn (
            Container $container,
        ): AdministratorDashboardHandler => new AdministratorDashboardHandler(
            self::service($container, ContentService::class),
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(AdministratorContentEditorHandler::class, static fn (
            Container $container,
        ): AdministratorContentEditorHandler => new AdministratorContentEditorHandler(
            self::service($container, ContentService::class),
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(AdministratorCreateContentHandler::class, static fn (
            Container $container,
        ): AdministratorCreateContentHandler => new AdministratorCreateContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorUpdateContentHandler::class, static fn (
            Container $container,
        ): AdministratorUpdateContentHandler => new AdministratorUpdateContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorTransitionContentHandler::class, static fn (
            Container $container,
        ): AdministratorTransitionContentHandler => new AdministratorTransitionContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorTrashContentHandler::class, static fn (
            Container $container,
        ): AdministratorTrashContentHandler => new AdministratorTrashContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorRestoreContentHandler::class, static fn (
            Container $container,
        ): AdministratorRestoreContentHandler => new AdministratorRestoreContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorExtensionsHandler::class, static fn (
            Container $container,
        ): AdministratorExtensionsHandler => new AdministratorExtensionsHandler(
            self::service($container, ExtensionManager::class),
            self::service($container, AdministratorRenderer::class),
            dirname(__DIR__, 2) . '/storage/tmp',
        ), true);
        $container->share(AdministratorExtensionActionHandler::class, static fn (
            Container $container,
        ): AdministratorExtensionActionHandler => new AdministratorExtensionActionHandler(
            self::service($container, ExtensionManager::class),
        ), true);
        $container->share(AdministratorSettingsHandler::class, static fn (
            Container $container,
        ): AdministratorSettingsHandler => new AdministratorSettingsHandler(
            self::service($container, SiteSettings::class),
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(McpHttpHandler::class, static function (
            Container $container,
        ): McpHttpHandler {
            $configuration = self::service($container, ApplicationConfiguration::class);
            $host = parse_url($configuration->baseUrl, PHP_URL_HOST);

            if (!is_string($host) || $host === '') {
                throw new RuntimeException('The configured Kumwe base URL has no usable MCP host.');
            }

            return new McpHttpHandler(
                self::service($container, KumweMcpServerFactory::class),
                self::service($container, KumweMcpHandlers::class),
                self::service($container, ResponseFactoryInterface::class),
                self::service($container, StreamFactoryInterface::class),
                self::service($container, LoggerInterface::class),
                $configuration->maxBodyBytes,
                [$host],
            );
        }, true);
    }

    private function configureApplication(Application $application, Container $container): void
    {
        $application->pipe(RequestIdMiddleware::class);
        $application->pipe(ProblemDetailsMiddleware::class);
        $application->pipe(TrustedHostMiddleware::class);
        $application->pipe(BodyLimitMiddleware::class);
        $application->pipe(SecurityHeadersMiddleware::class);
        $application->pipe(RouteMiddleware::class);
        $application->pipe(ImplicitHeadMiddleware::class);
        $application->pipe(ImplicitOptionsMiddleware::class);
        $application->pipe(MethodNotAllowedMiddleware::class);
        $application->pipe(AdministratorSessionMiddleware::class);
        $application->pipe(BearerAuthenticationMiddleware::class);
        $application->pipe(DispatchMiddleware::class);
        $application->pipe(NotFoundHandler::class);

        $application->get('/', HomePageHandler::class, 'site.home');
        $application->get('/health/live', LivenessHandler::class, 'health.live');
        $application->get('/health/ready', ReadinessHandler::class, 'health.ready');
        $application->route(
            '/administrator/login',
            AdministratorLoginHandler::class,
            ['GET', 'POST'],
            'administrator.login',
        );
        $application->get('/administrator', AdministratorDashboardHandler::class, 'administrator.index');
        $application->get(
            '/administrator/content/new',
            AdministratorContentEditorHandler::class,
            'administrator.content.new',
        );
        $application->get(
            '/administrator/content/{id}/edit',
            AdministratorContentEditorHandler::class,
            'administrator.content.edit',
        );
        $application->post(
            '/administrator/content',
            [AdministratorCsrfMiddleware::class, AdministratorCreateContentHandler::class],
            'administrator.content.create',
        );
        $application->post(
            '/administrator/content/{id}',
            [AdministratorCsrfMiddleware::class, AdministratorUpdateContentHandler::class],
            'administrator.content.update',
        );
        $application->post(
            '/administrator/content/{id}/transition',
            [AdministratorCsrfMiddleware::class, AdministratorTransitionContentHandler::class],
            'administrator.content.transition',
        );
        $application->post(
            '/administrator/content/{id}/trash',
            [AdministratorCsrfMiddleware::class, AdministratorTrashContentHandler::class],
            'administrator.content.trash',
        );
        $application->post(
            '/administrator/content/{id}/restore',
            [AdministratorCsrfMiddleware::class, AdministratorRestoreContentHandler::class],
            'administrator.content.restore',
        );
        $application->post(
            '/administrator/logout',
            [AdministratorCsrfMiddleware::class, AdministratorLogoutHandler::class],
            'administrator.logout',
        );
        $application->get(
            '/administrator/extensions',
            AdministratorExtensionsHandler::class,
            'administrator.extensions',
        );
        $application->post(
            '/administrator/extensions',
            [AdministratorCsrfMiddleware::class, AdministratorExtensionsHandler::class],
            'administrator.extensions.install',
        );
        $application->post(
            '/administrator/extensions/action',
            [AdministratorCsrfMiddleware::class, AdministratorExtensionActionHandler::class],
            'administrator.extensions.action',
        );
        $application->get(
            '/administrator/settings',
            AdministratorSettingsHandler::class,
            'administrator.settings',
        );
        $application->post(
            '/administrator/settings',
            [AdministratorCsrfMiddleware::class, AdministratorSettingsHandler::class],
            'administrator.settings.update',
        );
        $application->get('/pages/{slug}', PublishedContentHandler::class, 'site.content.page');
        $application->get('/api/v1', ApiIndexHandler::class, 'api.v1.index');

        $contentCollection = $application->get(
            '/api/v1/content',
            ContentCollectionHandler::class,
            'api.v1.content.collection',
        );
        $contentCollection->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.read'],
        ]);
        $contentCreate = $application->post(
            '/api/v1/content',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                ContentCollectionHandler::class,
            ],
            'api.v1.content.create',
        );
        $contentCreate->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.create'],
        ]);
        $contentItem = $application->get(
            '/api/v1/content/{id}',
            ContentItemHandler::class,
            'api.v1.content.read',
        );
        $contentItem->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.read'],
        ]);
        $contentUpdate = $application->patch(
            '/api/v1/content/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentItemHandler::class,
            ],
            'api.v1.content.update',
        );
        $contentUpdate->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.update'],
        ]);
        $contentDelete = $application->delete(
            '/api/v1/content/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentItemHandler::class,
            ],
            'api.v1.content.trash',
        );
        $contentDelete->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.delete'],
        ]);
        $contentTransition = $application->post(
            '/api/v1/content/{id}/transition',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentTransitionHandler::class,
            ],
            'api.v1.content.transition',
        );
        $contentTransition->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.publish'],
        ]);
        $contentRestore = $application->post(
            '/api/v1/content/{id}/restore',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentRestoreHandler::class,
            ],
            'api.v1.content.restore',
        );
        $contentRestore->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.delete'],
        ]);

        $planRoute = $application->post(
            '/api/v1/plans',
            [RequireIdempotencyKeyMiddleware::class, PlanPreviewHandler::class],
            'api.v1.plans.preview',
        );
        $planRoute->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.read'],
        ]);

        $mcpRoute = $application->route('/mcp', McpHttpHandler::class, ['GET', 'POST', 'DELETE'], 'mcp');
        $mcpRoute->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.read'],
        ]);
        $application->route('/mcp', McpHttpHandler::class, ['OPTIONS'], 'mcp.options');
        self::service($container, ActiveExtensionSet::class)->registerRoutes($application);
    }

    private function registerConsole(Container $container): void
    {
        $container->share(PurgeAdministratorSessionsHandler::class, static fn (
            Container $container,
        ): PurgeAdministratorSessionsHandler => new PurgeAdministratorSessionsHandler(
            self::service($container, AdministratorSessionStore::class),
        ), true);
        $container->share(RebuildExtensionMapHandler::class, static fn (
            Container $container,
        ): RebuildExtensionMapHandler => new RebuildExtensionMapHandler(
            self::service($container, ExtensionRuntimeMapCompiler::class),
        ), true);
        $container->share(TransitionContentHandler::class, static fn (
            Container $container,
        ): TransitionContentHandler => new TransitionContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(JobHandlerRegistry::class, static fn (Container $container): JobHandlerRegistry =>
            new JobHandlerRegistry([
                self::service($container, PurgeAdministratorSessionsHandler::class),
                self::service($container, RebuildExtensionMapHandler::class),
                self::service($container, TransitionContentHandler::class),
            ]), true);
        $container->share(Worker::class, static fn (Container $container): Worker => new Worker(
            self::service($container, JobQueue::class),
            self::service($container, JobHandlerRegistry::class),
        ), true);
        $container->share(Output::class, static fn (): Output => StreamOutput::standard(), true);
        $container->share(MigrateCommand::class, static fn (Container $container): MigrateCommand =>
            new MigrateCommand(self::service($container, MigrationRunner::class)), true);
        $container->share(MigrationStatusCommand::class, static fn (Container $container): MigrationStatusCommand =>
            new MigrationStatusCommand(self::service($container, MigrationRunner::class)), true);
        $container->share(HealthCheckCommand::class, static fn (Container $container): HealthCheckCommand =>
            new HealthCheckCommand(self::service($container, ReadinessProbe::class)), true);
        $container->share(CreateAdministratorCommand::class, static fn (
            Container $container,
        ): CreateAdministratorCommand => new CreateAdministratorCommand(
            self::service($container, AdministratorIdentityGateway::class),
        ), true);
        $container->share(CreateAccessTokenCommand::class, static fn (
            Container $container,
        ): CreateAccessTokenCommand => new CreateAccessTokenCommand(
            self::service($container, AdministratorIdentityGateway::class),
        ), true);
        $container->share(ListExtensionsCommand::class, static fn (
            Container $container,
        ): ListExtensionsCommand => new ListExtensionsCommand(
            self::service($container, ExtensionManager::class),
        ), true);
        $container->share(InstallExtensionCommand::class, static fn (
            Container $container,
        ): InstallExtensionCommand => new InstallExtensionCommand(
            self::service($container, ExtensionManager::class),
        ), true);
        $container->share(ActivateExtensionCommand::class, static fn (
            Container $container,
        ): ActivateExtensionCommand => new ActivateExtensionCommand(
            self::service($container, ExtensionManager::class),
        ), true);
        $container->share(DisableExtensionCommand::class, static fn (
            Container $container,
        ): DisableExtensionCommand => new DisableExtensionCommand(
            self::service($container, ExtensionManager::class),
        ), true);
        $container->share(UninstallExtensionCommand::class, static fn (
            Container $container,
        ): UninstallExtensionCommand => new UninstallExtensionCommand(
            self::service($container, ExtensionManager::class),
        ), true);
        $container->share(QueueWorkCommand::class, static fn (Container $container): QueueWorkCommand =>
            new QueueWorkCommand(self::service($container, Worker::class)), true);
        $container->share(ScheduleRunCommand::class, static fn (Container $container): ScheduleRunCommand =>
            new ScheduleRunCommand(self::service($container, Scheduler::class)), true);
        $container->share(CreateScheduleCommand::class, static fn (
            Container $container,
        ): CreateScheduleCommand => new CreateScheduleCommand(
            self::service($container, ScheduleRepository::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ListSchedulesCommand::class, static fn (Container $container): ListSchedulesCommand =>
            new ListSchedulesCommand(self::service($container, ScheduleRepository::class)), true);
        $container->share(McpServeCommand::class, static fn (Container $container): McpServeCommand =>
            new McpServeCommand(
                self::service($container, KumweMcpServerFactory::class),
                self::service($container, KumweMcpHandlers::class),
                self::service($container, LoggerInterface::class),
            ), true);
        $container->share(ConsoleApplication::class, static fn (Container $container): ConsoleApplication =>
            new ConsoleApplication([
                self::service($container, MigrateCommand::class),
                self::service($container, MigrationStatusCommand::class),
                self::service($container, HealthCheckCommand::class),
                self::service($container, CreateAdministratorCommand::class),
                self::service($container, CreateAccessTokenCommand::class),
                self::service($container, ListExtensionsCommand::class),
                self::service($container, InstallExtensionCommand::class),
                self::service($container, ActivateExtensionCommand::class),
                self::service($container, DisableExtensionCommand::class),
                self::service($container, UninstallExtensionCommand::class),
                self::service($container, QueueWorkCommand::class),
                self::service($container, ScheduleRunCommand::class),
                self::service($container, CreateScheduleCommand::class),
                self::service($container, ListSchedulesCommand::class),
                self::service($container, McpServeCommand::class),
            ], self::service($container, Output::class)), true);
    }

    private function registerMcp(Container $container, string $root): void
    {
        $container->share(McpCapabilityCatalog::class, new McpCapabilityCatalog(), true);
        $container->share(SessionStoreInterface::class, static fn (Container $container): SessionStoreInterface =>
            new FileSessionStore(
                $root . '/storage/sessions/mcp',
                3_600,
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(KumweMcpHandlers::class, static fn (Container $container): KumweMcpHandlers =>
            new KumweMcpHandlers(self::service($container, McpCapabilityCatalog::class)), true);
        $container->share(KumweMcpServerFactory::class, static fn (Container $container): KumweMcpServerFactory =>
            new KumweMcpServerFactory(
                self::service($container, McpCapabilityCatalog::class),
                sessions: self::service($container, SessionStoreInterface::class),
            ), true);
    }

    /**
     * @template T of object
     * @param class-string<T> $service
     * @return T
     */
    private static function service(Container $container, string $service): object
    {
        $resolved = $container->get($service);

        if (!$resolved instanceof $service) {
            throw new RuntimeException(sprintf('Container service "%s" resolved to an invalid value.', $service));
        }

        return $resolved;
    }
}
