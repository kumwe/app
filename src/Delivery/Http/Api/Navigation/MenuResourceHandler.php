<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Navigation;

use Kumwe\CMS\Navigation\Application\NavigationService;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class MenuResourceHandler implements RequestHandlerInterface
{
    public function __construct(private NavigationService $navigation, private NavigationApiResponder $responder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $id = NavigationApiRequest::route($request, 'id');
            $menu = $this->navigation->menu($id);
            $method = strtoupper($request->getMethod());
            if ($method === 'GET') {
                return $this->responder->record($menu);
            }

            $version = NavigationApiRequest::expectedVersion($request, $menu->version);
            if ($method === 'DELETE') {
                $this->navigation->deleteMenu(NavigationApiRequest::principal($request)->subject(), $id, $version);

                return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
            }

            $body = NavigationApiRequest::json($request);
            return $this->responder->record($this->navigation->updateMenu(
                NavigationApiRequest::principal($request)->subject(),
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
