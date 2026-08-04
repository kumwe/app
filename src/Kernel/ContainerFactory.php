<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel;

use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Kumwe\CMS\Delivery\Console\Command\HealthCheckCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrateCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrationStatusCommand;
use Kumwe\CMS\Delivery\Console\ConsoleApplication;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Delivery\Console\StreamOutput;
use Kumwe\CMS\Http\Handler\AdministratorBoundaryHandler;
use Kumwe\CMS\Http\Handler\ApiIndexHandler;
use Kumwe\CMS\Http\Handler\HomePageHandler;
use Kumwe\CMS\Http\Handler\LivenessHandler;
use Kumwe\CMS\Http\Handler\NotFoundHandler;
use Kumwe\CMS\Http\Handler\ReadinessHandler;
use Kumwe\CMS\Http\Middleware\BodyLimitMiddleware;
use Kumwe\CMS\Http\Middleware\ProblemDetailsMiddleware;
use Kumwe\CMS\Http\Middleware\RequestIdMiddleware;
use Kumwe\CMS\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\CMS\Http\Middleware\TrustedHostMiddleware;
use Kumwe\CMS\Http\Security\TrustedHostMatcher;
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
use Mezzio\Application;
use Mezzio\ApplicationPipeline;
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
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

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
        $this->registerHttp($container, $configuration);
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
    ): void
    {
        $databaseConfiguration = $configuration->database;
        $container->share(DatabaseInterface::class, static fn (): DatabaseInterface =>
            (new PostgreSqlDatabaseFactory($databaseConfiguration))->create(), true);
        $container->share(TransactionManager::class, static fn (Container $container): TransactionManager =>
            new JoomlaTransactionManager($container->get(DatabaseInterface::class)), true);
        $container->share(MigrationRepository::class, static fn (Container $container): MigrationRepository =>
            new PostgreSqlMigrationRepository(
                $container->get(DatabaseInterface::class),
                $databaseConfiguration->schema,
            ), true);
        $container->share(MigrationLock::class, static fn (Container $container): MigrationLock =>
            new PostgreSqlMigrationLock($container->get(DatabaseInterface::class)), true);
        $container->share(MigrationRunner::class, static fn (Container $container): MigrationRunner =>
            new MigrationRunner(
                database: $container->get(DatabaseInterface::class),
                repository: $container->get(MigrationRepository::class),
                lock: $container->get(MigrationLock::class),
                transactions: $container->get(TransactionManager::class),
                migrations: [
                    new Version202608040001CreateSystemTables($databaseConfiguration->schema),
                    SchemaMigration::fromFile(
                        '20260804000300_create_identity_and_audit',
                        $databaseConfiguration->schema,
                        $root . '/database/schema/phase3.sql',
                    ),
                ],
            ), true);
        $container->share(ReadinessProbe::class, static fn (Container $container): ReadinessProbe =>
            new ReadinessProbe(
                database: $container->get(DatabaseInterface::class),
                logger: $container->get(LoggerInterface::class),
                schema: $databaseConfiguration->schema,
                requiredMigration: '20260804000300_create_identity_and_audit',
            ), true);
    }

    private function registerHttp(Container $container, ApplicationConfiguration $configuration): void
    {
        $container->share(ResponseFactoryInterface::class, new ResponseFactory(), true);
        $container->share(StreamFactoryInterface::class, new StreamFactory(), true);
        $container->share(RouterInterface::class, static fn (Container $container): RouterInterface =>
            new FastRouteRouter(null, null, $container->get('config')['router']['fastroute']), true);
        $container->share(RouteCollector::class, static fn (Container $container): RouteCollector =>
            new RouteCollector($container->get(RouterInterface::class), true), true);
        $container->alias(RouteCollectorInterface::class, RouteCollector::class);
        $container->share(MiddlewareContainer::class, static fn (Container $container): MiddlewareContainer =>
            new MiddlewareContainer($container), true);
        $container->share(MiddlewareFactory::class, static fn (Container $container): MiddlewareFactory =>
            new MiddlewareFactory($container->get(MiddlewareContainer::class)), true);
        $container->alias(MiddlewareFactoryInterface::class, MiddlewareFactory::class);
        $container->share(ApplicationPipeline::class, new MiddlewarePipe(), true);
        $container->share(EmitterInterface::class, static function (): EmitterInterface {
            $emitter = new EmitterStack();
            $emitter->push(new SapiEmitter());

            return $emitter;
        }, true);
        $container->share(ServerRequestErrorResponseGenerator::class, static fn (Container $container):
            ServerRequestErrorResponseGenerator => new ServerRequestErrorResponseGenerator(
                $container->get(ResponseFactoryInterface::class),
                false,
            ), true);
        $container->share(RequestHandlerRunnerInterface::class, static fn (Container $container):
            RequestHandlerRunnerInterface => new RequestHandlerRunner(
                $container->get(ApplicationPipeline::class),
                $container->get(EmitterInterface::class),
                static fn () => ServerRequestFactory::fromGlobals(),
                $container->get(ServerRequestErrorResponseGenerator::class),
            ), true);

        $this->registerMiddleware($container, $configuration);
        $this->registerHandlers($container);
        $container->share(Application::class, function (Container $container): Application {
            $application = new Application(
                $container->get(MiddlewareFactoryInterface::class),
                $container->get(ApplicationPipeline::class),
                $container->get(RouteCollectorInterface::class),
                $container->get(RequestHandlerRunnerInterface::class),
            );
            $this->configureApplication($application);

            return $application;
        }, true);
    }

    private function registerMiddleware(Container $container, ApplicationConfiguration $configuration): void
    {
        $container->share(RequestIdMiddleware::class, new RequestIdMiddleware(), true);
        $container->share(ProblemDetailsMiddleware::class, static fn (Container $container):
            ProblemDetailsMiddleware => new ProblemDetailsMiddleware(
                $container->get(LoggerInterface::class),
                $configuration->debug,
            ), true);
        $container->share(TrustedHostMiddleware::class, new TrustedHostMiddleware(
            new TrustedHostMatcher($configuration->trustedHosts),
        ), true);
        $container->share(BodyLimitMiddleware::class, new BodyLimitMiddleware($configuration->maxBodyBytes), true);
        $container->share(SecurityHeadersMiddleware::class, new SecurityHeadersMiddleware(
            $configuration->isProduction(),
        ), true);
        $container->share(RouteMiddleware::class, static fn (Container $container): RouteMiddleware =>
            new RouteMiddleware($container->get(RouterInterface::class)), true);
        $container->share(ImplicitHeadMiddleware::class, static fn (Container $container): ImplicitHeadMiddleware =>
            new ImplicitHeadMiddleware(
                $container->get(RouterInterface::class),
                $container->get(StreamFactoryInterface::class),
            ), true);
        $container->share(ImplicitOptionsMiddleware::class, static fn (Container $container):
            ImplicitOptionsMiddleware => new ImplicitOptionsMiddleware(
                $container->get(ResponseFactoryInterface::class),
            ), true);
        $container->share(MethodNotAllowedMiddleware::class, static fn (Container $container):
            MethodNotAllowedMiddleware => new MethodNotAllowedMiddleware(
                $container->get(ResponseFactoryInterface::class),
            ), true);
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
            new ReadinessHandler($container->get(ReadinessProbe::class)), true);
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
        $application->pipe(DispatchMiddleware::class);
        $application->pipe(NotFoundHandler::class);

        $application->get('/', HomePageHandler::class, 'site.home');
        $application->get('/health/live', LivenessHandler::class, 'health.live');
        $application->get('/health/ready', ReadinessHandler::class, 'health.ready');
        $application->get('/administrator', AdministratorBoundaryHandler::class, 'administrator.index');
        $application->get('/api/v1', ApiIndexHandler::class, 'api.v1.index');
    }

    private function registerConsole(Container $container): void
    {
        $container->share(Output::class, static fn (): Output => StreamOutput::standard(), true);
        $container->share(MigrateCommand::class, static fn (Container $container): MigrateCommand =>
            new MigrateCommand($container->get(MigrationRunner::class)), true);
        $container->share(MigrationStatusCommand::class, static fn (Container $container): MigrationStatusCommand =>
            new MigrationStatusCommand($container->get(MigrationRunner::class)), true);
        $container->share(HealthCheckCommand::class, static fn (Container $container): HealthCheckCommand =>
            new HealthCheckCommand($container->get(ReadinessProbe::class)), true);
        $container->share(ConsoleApplication::class, static fn (Container $container): ConsoleApplication =>
            new ConsoleApplication([
                $container->get(MigrateCommand::class),
                $container->get(MigrationStatusCommand::class),
                $container->get(HealthCheckCommand::class),
            ], $container->get(Output::class)), true);
    }
}
