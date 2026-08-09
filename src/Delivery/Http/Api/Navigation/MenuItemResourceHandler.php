<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Navigation;

use Kumwe\CMS\Navigation\Application\NavigationService;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Serves `GET`, `PATCH` and `DELETE` on `/api/v1/menu-items/{id}`, the single-item end of the navigation API.
 *
 * One handler covers all three methods because each begins the same way, by loading the addressed
 * item: the read answers with it, and both writes need the version it carries to settle the
 * `If-Match` precondition. The `PATCH` body is merged over that loaded record field by field, which
 * matters most for the link target: the `target_type`, `content_id` and `target_url` triple is only
 * forwarded when the body mentions at least one of them, because a null target type tells
 * `NavigationService` to leave the stored target alone rather than clear it.
 *
 * @since  2.0.0
 */
final readonly class MenuItemResourceHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the service that owns the item rules and the responder that renders the answer.
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
     * Read, rewrite or delete the addressed menu item, according to the request method.
     *
     * `GET` answers with the record and never consults the precondition. `PATCH` and `DELETE` settle
     * `If-Match` against the loaded version first, so a client working from a stale copy is refused
     * with 412 before the service is asked to write. Every `PATCH` field the body omits is refilled
     * from the loaded record, so an update that only moves the item keeps its title, slug and target;
     * changing the parent or the slug moves the whole subtree, which the service resolves. A delete
     * answers 204 with an empty body, so it does not pass through the responder's record path.
     * Failures are handed to the responder, which answers the ones it recognises and rethrows the rest.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the item and
     *          whose `If-Match` header quotes the version the caller loaded.
     *
     * @return  ResponseInterface  The item as JSON tagged with its version, an empty 204 after a delete,
     *          or a problem document saying why the request was refused.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $id = NavigationApiRequest::route($request, 'id');
            $item = $this->navigation->item(NavigationApiRequest::context($request), $id);
            $method = strtoupper($request->getMethod());
            if ($method === 'GET') {
                return $this->responder->record($item);
            }

            $version = NavigationApiRequest::expectedVersion($request, $item->version);
            if ($method === 'DELETE') {
                $this->navigation->deleteItem(NavigationApiRequest::context($request), $id, $version);

                return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
            }

            $body = NavigationApiRequest::json($request);
            $targetChanged = array_key_exists('target_type', $body)
                || array_key_exists('content_id', $body)
                || array_key_exists('target_url', $body);
            return $this->responder->record($this->navigation->updateItem(
                NavigationApiRequest::context($request),
                $id,
                $version,
                array_key_exists('parent_id', $body)
                    ? NavigationApiRequest::nullableString($body, 'parent_id')
                    : $item->parentId,
                array_key_exists('title', $body) ? NavigationApiRequest::string($body, 'title') : $item->title,
                array_key_exists('slug', $body) ? NavigationApiRequest::string($body, 'slug') : $item->slug,
                array_key_exists('position', $body)
                    ? NavigationApiRequest::integer($body, 'position')
                    : $item->position,
                $targetChanged
                    ? (array_key_exists('target_type', $body)
                        ? NavigationApiRequest::targetType($body)
                        : $item->targetType)
                    : null,
                array_key_exists('content_id', $body)
                    ? NavigationApiRequest::nullableString($body, 'content_id')
                    : $item->contentId,
                array_key_exists('target_url', $body)
                    ? NavigationApiRequest::nullableString($body, 'target_url')
                    : $item->targetUrl,
            ));
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
