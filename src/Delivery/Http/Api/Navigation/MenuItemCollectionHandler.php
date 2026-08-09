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

/**
 * Serves `GET` and `POST` on `/api/v1/menus/{menuId}/items`, the items belonging to one menu.
 *
 * Items are addressed through their menu on this route, so both arms resolve `menuId` first and leave
 * `NavigationService` to prove the actor may reach that menu before anything is listed or inserted —
 * which is why an actor with no reach into the menu is refused rather than handed an empty list. The
 * listing is rendered here rather than through `NavigationApiResponder`, since a collection carries no
 * one version to build an `ETag` from; the create goes through the responder and points its `Location`
 * at `/api/v1/menu-items/{id}`, the resource route items are read and edited on, not at a path below
 * this one.
 *
 * @since  2.0.0
 */
final readonly class MenuItemCollectionHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the service that owns the item rules and the responder that renders the answer.
     *
     * @param  NavigationService       $navigation  Application service performing the listing and the create.
     * @param  NavigationApiResponder  $responder   Renders stored records and maps failures onto problem documents.
     *
     * @since  2.0.0
     */
    public function __construct(private NavigationService $navigation, private NavigationApiResponder $responder)
    {
    }

    /**
     * List the addressed menu's items, or add one to it.
     *
     * `target_type` is forwarded only when the body actually carries the member: omitting it passes null
     * to the service, which selects the legacy content item resolved by slug at render time, whereas
     * sending it holds the value to `content`, `anchor` or `url`. `position` defaults to 0 when absent,
     * placing a new item ahead of its siblings. Both arms run inside the try, so every failure reaches
     * the responder, which answers the ones the navigation API models and re-throws the rest rather than
     * guessing a status for them.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request whose `menuId` route attribute
     *          names the menu; on a `POST` its JSON body describes the item to add.
     *
     * @return  ResponseInterface  200 with the visible items under `items`, ordered so parents precede
     *          children, 201 with the created item, its `ETag` and a `Location`, or a problem document.
     *
     * @since   2.0.0
     */
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
