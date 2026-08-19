<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Navigation;

use Kumwe\App\Navigation\Application\NavigationService;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Serves `GET`, `PATCH` and `DELETE` on `/api/v1/menus/{id}`, the single-menu end of the navigation API.
 *
 * One handler covers all three methods because each begins the same way, by loading the addressed
 * menu: the read answers with it, and both writes need the version it carries to settle the
 * `If-Match` precondition before the store is touched. `PATCH` is a genuine partial update — a field
 * the body omits is refilled from the loaded record — so a client can retitle a menu without
 * resending the handle that themes and settings refer to it by.
 *
 * @since  2.0.0
 */
final readonly class MenuResourceHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the service that owns the menu rules and the responder that renders the answer.
     *
     * @param  NavigationService       $navigation  Application service performing the read, update and delete.
     * @param  NavigationApiResponder  $responder   Renders stored records and maps failures onto problem documents.
     *
     * @since  2.0.0
     */
    public function __construct(private NavigationService $navigation, private NavigationApiResponder $responder)
    {
    }

    /**
     * Read, rename or delete the addressed menu, according to the request method.
     *
     * `GET` answers with the record and never consults the precondition. `PATCH` and `DELETE` settle
     * `If-Match` against the loaded version first, so a client working from a stale copy is refused
     * with 412 before the service is asked to write. Deleting a menu takes its items with it, and is
     * answered 204 with an empty body, so it does not pass through the responder's record path.
     * Failures are handed to the responder, which answers the ones it recognises and rethrows the rest.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the menu and
     *          whose `If-Match` header quotes the version the caller loaded.
     *
     * @return  ResponseInterface  The menu as JSON tagged with its version, an empty 204 after a delete,
     *          or a problem document saying why the request was refused.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $id = NavigationApiRequest::route($request, 'id');
            $menu = $this->navigation->menu(NavigationApiRequest::context($request), $id);
            $method = strtoupper($request->getMethod());
            if ($method === 'GET') {
                return $this->responder->record($menu);
            }

            $version = NavigationApiRequest::expectedVersion($request, $menu->version);
            if ($method === 'DELETE') {
                $this->navigation->deleteMenu(NavigationApiRequest::context($request), $id, $version);

                return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
            }

            $body = NavigationApiRequest::json($request);
            return $this->responder->record($this->navigation->updateMenu(
                NavigationApiRequest::context($request),
                $id,
                $version,
                array_key_exists('handle', $body) ? NavigationApiRequest::string($body, 'handle') : $menu->handle,
                array_key_exists('title', $body) ? NavigationApiRequest::string($body, 'title') : $menu->title,
            ));
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
