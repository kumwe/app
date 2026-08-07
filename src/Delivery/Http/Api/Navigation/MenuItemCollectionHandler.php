<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Navigation;

use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class MenuItemCollectionHandler implements RequestHandlerInterface
{
    public function __construct(private NavigationService $navigation, private NavigationApiResponder $responder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $menuId = NavigationApiRequest::route($request, 'menuId');
            if (strtoupper($request->getMethod()) === 'GET') {
                return new JsonResponse(['items' => array_map(
                    static fn (MenuItemRecord $item): array => $item->toArray(),
                    $this->navigation->items(NavigationApiRequest::context($request), $menuId),
                )], 200, ['Cache-Control' => 'no-store']);
            }

            $body = NavigationApiRequest::json($request);
            $item = $this->navigation->createItem(
                NavigationApiRequest::context($request),
                $menuId,
                NavigationApiRequest::nullableString($body, 'parent_id'),
                NavigationApiRequest::string($body, 'title'),
                NavigationApiRequest::string($body, 'slug'),
                NavigationApiRequest::integer($body, 'position'),
                array_key_exists('target_type', $body)
                    ? NavigationApiRequest::targetType($body)
                    : null,
                NavigationApiRequest::nullableString($body, 'content_id'),
                NavigationApiRequest::nullableString($body, 'target_url'),
            );

            return $this->responder->record($item, 201, ['Location' => '/api/v1/menu-items/' . $item->id]);
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
