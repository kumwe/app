<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Navigation;

use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class MenuCollectionHandler implements RequestHandlerInterface
{
    public function __construct(private NavigationService $navigation, private NavigationApiResponder $responder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            return new JsonResponse(['items' => array_map(
                static fn (MenuRecord $menu): array => $menu->toArray(),
                $this->navigation->menus(NavigationApiRequest::context($request)),
            )], 200, ['Cache-Control' => 'no-store']);
        }

        try {
            $body = NavigationApiRequest::json($request);
            $menu = $this->navigation->createMenu(
                NavigationApiRequest::context($request),
                NavigationApiRequest::string($body, 'handle'),
                NavigationApiRequest::string($body, 'title'),
            );

            return $this->responder->record($menu, 201, ['Location' => '/api/v1/menus/' . $menu->id]);
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
