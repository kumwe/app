<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Functional\Http;

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
final class KernelTest extends TestCase
{
    public function testContainerReturnsOneConfiguredApplication(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());

        self::assertSame($container->get(Application::class), $container->get(Application::class));
    }

    public function testPublicHealthAndProtectedBoundaries(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
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
        self::assertSame(303, $admin->getStatusCode());
        self::assertSame('/administrator/login', $admin->getHeaderLine('Location'));
        self::assertSame(405, $unsafeApi->getStatusCode());
    }

    public function testTrustedProxyNormalizationPrecedesHostAndTransportSecurity(): void
    {
        $values = $this->productionValues();
        $values['APP_TRUSTED_PROXIES'] = '10.20.0.0/16';
        $application = (new ContainerFactory())->create(new Environment($values))->get(Application::class);
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://proxy.internal/health/live',
            ['REMOTE_ADDR' => '10.20.0.10'],
        )->withHeader('Forwarded', 'for=203.0.113.50;proto=https;host=kumwe.test');

        $response = $application->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testUntrustedPeerCannotUseForwardedHostToPassTheHostBoundary(): void
    {
        $values = $this->productionValues();
        $values['APP_TRUSTED_PROXIES'] = '10.20.0.0/16';
        $application = (new ContainerFactory())->create(new Environment($values))->get(Application::class);
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://attacker.test/health/live',
            ['REMOTE_ADDR' => '198.51.100.12'],
        )->withHeader('Forwarded', 'for=203.0.113.50;proto=https;host=kumwe.test');

        $response = $application->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    /**
     * @return array<string, string>
     */
    private function productionValues(): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_BASE_URL' => 'https://kumwe.test',
            'APP_TRUSTED_HOSTS' => 'kumwe.test',
            'APP_SECRET' => str_repeat('a', 32),
            'DB_DRIVER' => 'pgsql',
            'DB_HOST' => 'postgres',
            'DB_PORT' => '5432',
            'DB_NAME' => 'kumwe',
            'DB_USER' => 'kumwe',
            'DB_PASSWORD' => 'secret',
            'DB_TABLE_PREFIX' => 'kumwe_',
            'DB_SERVER_VERSION' => '17',
            'DB_SSLMODE' => 'require',
            'REDIS_HOST' => 'redis',
        ];
    }
}
