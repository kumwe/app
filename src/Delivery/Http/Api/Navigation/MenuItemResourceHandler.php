<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Navigation;

use Kumwe\CMS\Navigation\Application\NavigationService;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class MenuItemResourceHandler implements RequestHandlerInterface
{
    public function __construct(private NavigationService $navigation, private NavigationApiResponder $responder)
    {
    }

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
