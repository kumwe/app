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

}
