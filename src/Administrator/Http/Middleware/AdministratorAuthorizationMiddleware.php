<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Middleware;

use InvalidArgumentException;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Domain\Capability;
use Laminas\Diactoros\Response\HtmlResponse;
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
     * Optionally wire the themed renderer a browser navigation's denial is drawn through.
     *
     * @param  ?AdministratorRenderer  $renderer  Renders the `access-denied` screen for a browser `GET`
     *         that is refused; null answers every denial with a problem document instead.
     *
     * @since  2.0.0
     */
    public function __construct(private ?AdministratorRenderer $renderer = null)
    {
    }

    /**
     * Pass the request on only when its route's declared capabilities are all held by the principal.
     *
     * Traffic outside `/administrator`, and the login form itself, is forwarded untouched. A request
     * whose route did not match carries no declaration and is also forwarded, so a mistyped
     * administrator URL still reaches the not-found handler instead of being reported as forbidden.
     * A denial names the capability the principal lacks, in the shape the caller can use: a browser
     * navigation receives the themed `access-denied` page, everything else an
     * `application/problem+json` document.
     *
     * @param   ServerRequestInterface   $request  Request that has already passed the administrator session
     *          middleware.
     * @param   RequestHandlerInterface  $handler  Next handler in the pipe, reached only when every requirement
     *          is met.
     *
     * @return  ResponseInterface  The handler's response, or a 403 page or problem document naming the first
     *          capability the principal lacks.
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
                return $this->denied($request, $capability);
            }
        }

        return $handler->handle($request);
    }

    /**
     * Answer a denied request in the shape its caller can actually use.
     *
     * A browser navigation — a `GET` or `HEAD` whose `Accept` header names `text/html` — is answered
     * with the themed `access-denied` screen: the actor's own capability-filtered navigation, a notice
     * naming the missing capability, and a way back to the dashboard, so a denial reads as a closed
     * door rather than a broken sign-in. Every other caller, and every denial when no renderer is
     * wired, receives the `application/problem+json` document an API client can branch on. Both shapes
     * carry the capability name and neither is cacheable.
     *
     * @param   ServerRequestInterface  $request     Denied request, deciding between page and problem document.
     * @param   Capability              $capability  First declared capability the principal does not hold.
     *
     * @return  ResponseInterface  A themed 403 HTML page for a browser navigation, or a 403 problem document.
     *
     * @since   2.0.0
     */
    private function denied(ServerRequestInterface $request, Capability $capability): ResponseInterface
    {
        $session = $request->getAttribute(AdministratorSession::REQUEST_ATTRIBUTE);

        if (
            $this->renderer !== null
            && $session instanceof AdministratorSession
            && in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)
            && str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html')
        ) {
            $held = [];
            foreach ($session->principal->capabilities() as $heldCapability) {
                $held[$heldCapability->value()] = true;
            }

            return new HtmlResponse($this->renderer->render('access-denied', [
                'csrf' => $session->csrfToken,
                'capabilities' => $held,
                'missing_capability' => $capability->value(),
            ]), 403, ['Cache-Control' => 'no-store']);
        }

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
