<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Functional\Http;

use Kumwe\CMS\Http\Handler\AdministratorBoundaryHandler;
use Kumwe\CMS\Http\Handler\ApiIndexHandler;
use Kumwe\CMS\Http\Handler\HomePageHandler;
use Kumwe\CMS\Http\Handler\LivenessHandler;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContainerFactory::class)]
#[CoversClass(HomePageHandler::class)]
#[CoversClass(LivenessHandler::class)]
#[CoversClass(ApiIndexHandler::class)]
#[CoversClass(AdministratorBoundaryHandler::class)]
final class KernelTest extends TestCase
{
    public function testContainerReturnsOneConfiguredApplication(): void
    {
        $container = (new ContainerFactory())->create(new Environment($this->environment()));

        self::assertSame($container->get(Application::class), $container->get(Application::class));
    }

    public function testPublicHealthAndProtectedBoundaries(): void
    {
        $container = (new ContainerFactory())->create(new Environment($this->environment()));
        $application = $container->get(Application::class);
        $factory = new ServerRequestFactory();

        $live = $application->handle(
            $factory->createServerRequest('GET', 'https://kumwe.test/health/live')->withHeader('Host', 'kumwe.test'),
        );
        $admin = $application->handle(
            $factory->createServerRequest('GET', 'https://kumwe.test/administrator')->withHeader('Host', 'kumwe.test'),
        );
        $unsafeApi = $application->handle(
            $factory->createServerRequest('POST', 'https://kumwe.test/api/v1')->withHeader('Host', 'kumwe.test'),
        );

        self::assertSame(200, $live->getStatusCode());
        self::assertSame(403, $admin->getStatusCode());
        self::assertSame(405, $unsafeApi->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_BASE_URL' => 'https://kumwe.test',
            'APP_TRUSTED_HOSTS' => 'kumwe.test',
            'APP_SECRET' => '0123456789abcdef0123456789abcdef',
            'DB_HOST' => 'postgres',
            'DB_PORT' => '5432',
            'DB_NAME' => 'kumwe',
            'DB_USER' => 'kumwe',
            'DB_PASSWORD' => 'secret',
            'DB_SCHEMA' => 'kumwe',
            'DB_SSLMODE' => 'disable',
        ];
    }
}
