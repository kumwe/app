<?php

declare(strict_types=1);

namespace KumweExample\Announcements;

use Kumwe\CMS\Extension\Runtime\ExtensionContainer;
use Kumwe\CMS\Extension\Runtime\ExtensionRouteRegistrar;
use Kumwe\CMS\Extension\Runtime\RuntimeExtension;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Provider implements RuntimeExtension
{
    private ?RequestHandlerInterface $handler = null;

    public function register(ExtensionContainer $container): void
    {
        $container->share(
            'extension.kumwe.announcements-example.handler',
            static fn (ExtensionContainer $container): RequestHandlerInterface =>
                new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new JsonResponse(['announcements' => []]);
                    }
                },
        );
    }

    public function boot(ExtensionContainer $container): void
    {
        $handler = $container->get('extension.kumwe.announcements-example.handler');
        if (!$handler instanceof RequestHandlerInterface) {
            throw new \LogicException('The announcements request handler is unavailable.');
        }
        $this->handler = $handler;
    }

    public function registerRoutes(ExtensionRouteRegistrar $routes): void
    {
        if ($this->handler === null) {
            throw new \LogicException('The announcements extension was not booted.');
        }
        $routes->route(
            '/extensions/kumwe/announcements-example/announcements',
            $this->handler,
            ['GET'],
            'extension.kumwe.announcements-example.index',
        );
    }
}
