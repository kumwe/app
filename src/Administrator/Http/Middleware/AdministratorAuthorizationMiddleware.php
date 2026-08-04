<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Middleware;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;
use Laminas\Diactoros\Response\JsonResponse;
use LogicException;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorAuthorizationMiddleware implements MiddlewareInterface
{
    public const OPTION_REQUIRED_CAPABILITIES = 'administrator_required_capabilities';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (!str_starts_with($path, '/administrator') || $path === '/administrator/login') {
            return $handler->handle($request);
        }

        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('Administrator authorization requires an authenticated principal.');
        }

        foreach ($this->requiredCapabilities($request) as $capability) {
            if (!$principal->hasCapability($capability)) {
                return new JsonResponse([
                    'type' => 'urn:kumwe:problem:insufficient-capability',
                    'title' => 'Forbidden',
                    'status' => 403,
                    'detail' => sprintf('Capability %s is required for this administrator operation.', $capability),
                ], 403, [
                    'Content-Type' => 'application/problem+json',
                    'Cache-Control' => 'no-store',
                ]);
            }
        }

        return $handler->handle($request);
    }

    /** @return list<Capability> */
    private function requiredCapabilities(ServerRequestInterface $request): array
    {
        $routeResult = $request->getAttribute(RouteResult::class);

        if (!$routeResult instanceof RouteResult || !$routeResult->isSuccess()) {
            return [];
        }

        $route = $routeResult->getMatchedRoute();

        if (!$route instanceof Route) {
            throw new LogicException('Administrator authorization requires a matched route.');
        }

        $configured = $route->getOptions()[self::OPTION_REQUIRED_CAPABILITIES] ?? null;

        if (!is_array($configured) || !array_is_list($configured) || $configured === []) {
            throw new LogicException('Every administrator route must declare required capabilities.');
        }

        $required = [];

        foreach ($configured as $capability) {
            if (!is_string($capability)) {
                throw new LogicException('Administrator capabilities must be configured as strings.');
            }

            try {
                $value = Capability::fromString($capability);
            } catch (InvalidArgumentException $exception) {
                throw new LogicException('An administrator route capability is invalid.', previous: $exception);
            }

            $required[$value->value()] = $value;
        }

        ksort($required, SORT_STRING);

        return array_values($required);
    }
}
