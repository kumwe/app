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

/**
 * Fail-closed gate in front of every `/administrator` route, enforcing the capabilities it declares.
 *
 * Administrator handlers declare what they need as a route option rather than checking it themselves,
 * and this middleware is what turns that declaration into a decision. It runs between the session
 * middleware that attaches the principal and the dispatch that reaches the handler. A route mounted
 * under `/administrator` that declares nothing is treated as a configuration defect and raises, which
 * is what stops a newly added screen from shipping unguarded. Only the login form is exempt, since it
 * cannot demand a capability from someone who has not signed in yet.
 *
 * @since  2.0.0
 */
final readonly class AdministratorAuthorizationMiddleware implements MiddlewareInterface
{
    /**
     * Route option under which a route declares the capabilities its actor must hold, all of them.
     *
     * @var    string
     * @since  2.0.0
     */
    public const OPTION_REQUIRED_CAPABILITIES = 'administrator_required_capabilities';

    /**
     * Pass the request on only when its route's declared capabilities are all held by the principal.
     *
     * Traffic outside `/administrator`, and the login form itself, is forwarded untouched. A request
     * whose route did not match carries no declaration and is also forwarded, so a mistyped
     * administrator URL still reaches the not-found handler instead of being reported as forbidden.
     * A denial is answered as an `application/problem+json` document that names the capability, so a
     * screen can tell the operator which grant they are missing rather than showing a bare 403.
     *
     * @param   ServerRequestInterface   $request  Request that has already passed the administrator session
     *          middleware.
     * @param   RequestHandlerInterface  $handler  Next handler in the pipe, reached only when every requirement
     *          is met.
     *
     * @return  ResponseInterface  The handler's response, or a 403 problem document naming the first capability
     *          the principal lacks.
     *
     * @throws  LogicException  When an administrator request carries no authenticated principal, or its route
     *          declares no usable capability list.
     *
     * @since   2.0.0
     */
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

    /**
     * Read the capability policy the matched route declares, de-duplicated and ordered by name.
     *
     * Sorting by capability name makes a denial reproducible: the same principal on the same route is
     * always told about the same missing capability, instead of one that depends on the order the
     * route happened to be registered in. A route that did not match yields an empty list, which is
     * the only way a request reaches the handler without a policy; a route that did match and
     * declares nothing usable is refused here, so the failure is a raised defect rather than an open
     * screen.
     *
     * @param   ServerRequestInterface  $request  Request the routing middleware has already run against.
     *
     * @return  list<Capability>  Declared capabilities ordered by name; empty only when no route matched.
     *
     * @throws  LogicException  When the matched route is not a `Route`, declares no non-empty list of capability
     *          strings, or names one that is not a valid capability.
     *
     * @since   2.0.0
     */
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
