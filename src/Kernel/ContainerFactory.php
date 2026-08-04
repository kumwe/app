<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel;

use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Kumwe\CMS\Delivery\Console\Command\HealthCheckCommand;
use Kumwe\CMS\Delivery\Console\Command\McpServeCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrateCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrationStatusCommand;
use Kumwe\CMS\Delivery\Console\ConsoleApplication;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Delivery\Console\StreamOutput;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Plan\PlanPreviewHandler;
use Kumwe\CMS\Delivery\Http\Api\Plan\SafePlanFactory;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Delivery\Http\Mcp\McpHttpHandler;
use Kumwe\CMS\Http\Handler\AdministratorBoundaryHandler;
use Kumwe\CMS\Http\Handler\ApiIndexHandler;
use Kumwe\CMS\Http\Handler\HomePageHandler;
use Kumwe\CMS\Http\Handler\LivenessHandler;
use Kumwe\CMS\Http\Handler\NotFoundHandler;
use Kumwe\CMS\Http\Handler\ReadinessHandler;
use Kumwe\CMS\Http\Middleware\BodyLimitMiddleware;
use Kumwe\CMS\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\CMS\Http\Middleware\ProblemDetailsMiddleware;
use Kumwe\CMS\Http\Middleware\RequestIdMiddleware;
use Kumwe\CMS\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\CMS\Http\Middleware\TrustedHostMiddleware;
use Kumwe\CMS\Http\Security\TrustedHostMatcher;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Identity\Infrastructure\Authentication\PostgreSqlAccessTokenVerifier;
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
                ],
            ), true);
        $container->share(ReadinessProbe::class, static fn (Container $container): ReadinessProbe =>
            new ReadinessProbe(
                database: self::service($container, DatabaseInterface::class),
                logger: self::service($container, LoggerInterface::class),
                schema: $databaseConfiguration->schema,
                requiredMigration: '20260804000700_create_automation_platform',
            ), true);
    }

    private function registerHttp(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
    ): void {
        $container->share(ResponseFactoryInterface::class, new ResponseFactory(), true);
        $container->share(StreamFactoryInterface::class, new StreamFactory(), true);
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
            $this->configureApplication($application);

            return $application;
        }, true);
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
        $container->share(HomePageHandler::class, new HomePageHandler(), true);
        $container->share(LivenessHandler::class, new LivenessHandler(), true);
        $container->share(ApiIndexHandler::class, new ApiIndexHandler(), true);
        $container->share(AdministratorBoundaryHandler::class, new AdministratorBoundaryHandler(), true);
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

    private function configureApplication(Application $application): void
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
        $application->pipe(BearerAuthenticationMiddleware::class);
        $application->pipe(DispatchMiddleware::class);
        $application->pipe(NotFoundHandler::class);

        $application->get('/', HomePageHandler::class, 'site.home');
        $application->get('/health/live', LivenessHandler::class, 'health.live');
        $application->get('/health/ready', ReadinessHandler::class, 'health.ready');
        $application->get('/administrator', AdministratorBoundaryHandler::class, 'administrator.index');
        $application->get('/api/v1', ApiIndexHandler::class, 'api.v1.index');

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
    }

    private function registerConsole(Container $container): void
    {
        $container->share(Output::class, static fn (): Output => StreamOutput::standard(), true);
        $container->share(MigrateCommand::class, static fn (Container $container): MigrateCommand =>
            new MigrateCommand(self::service($container, MigrationRunner::class)), true);
        $container->share(MigrationStatusCommand::class, static fn (Container $container): MigrationStatusCommand =>
            new MigrationStatusCommand(self::service($container, MigrationRunner::class)), true);
        $container->share(HealthCheckCommand::class, static fn (Container $container): HealthCheckCommand =>
            new HealthCheckCommand(self::service($container, ReadinessProbe::class)), true);
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
