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

/**
 * Serves `GET` and `POST` on `/api/v1/menus`, the collection end of the navigation API.
 *
 * Both methods share a handler because the collection route offers only those two operations and each
 * is a single call into `NavigationService` — list the menus this actor may manage, or create one. The
 * listing is rendered here rather than through `NavigationApiResponder`, since a collection carries no
 * one version to build an `ETag` from; the create goes through the responder so the stored menu comes
 * back already tagged with the version a later edit must quote, alongside a `Location` naming the
 * resource route that edit uses. Repeating a `POST` is made safe by the idempotency middleware the
 * route is mounted behind, so nothing here deduplicates one.
 *
 * @since  2.0.0
 */
final readonly class MenuCollectionHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the service that owns the menu rules and the responder that renders the answer.
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
     * List the menus the actor may manage, or create one from the request body.
     *
     * The service filters the listing row by row, so an actor whose grants reach only part of the site
     * gets a short list instead of a refusal, and an empty `items` means nothing is manageable rather
     * than that no menus exist. Only the create arm runs inside the try, so only its failures are
     * offered to the responder; a `GET` that reaches this handler without an authenticated execution
     * context surfaces as a fault rather than as a problem document.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request; on a `POST` its JSON body
     *          carries the new menu's `handle` and `title`.
     *
     * @return  ResponseInterface  200 with the visible menus under `items`, 201 with the created menu, its
     *          `ETag` and a `Location`, or a problem document saying why the create was refused.
     *
     * @throws  \InvalidArgumentException  When a `GET` arrives with no execution context attached, which
     *          means the route was mounted without the API authentication middleware.
     *
     * @since   2.0.0
     */
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
