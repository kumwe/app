<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Middleware;

use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Http\Middleware\RequestIdMiddleware;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorSessionMiddleware implements MiddlewareInterface
{
    public const COOKIE_NAME = 'kumwe_administrator';

    public function __construct(
        private AdministratorSessionStore $sessions,
        private AuthorizationGateway $authorization,
        private ?SiteContext $site = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (!str_starts_with($path, '/administrator') || $path === '/administrator/login') {
            return $handler->handle($request);
        }

        $token = $request->getCookieParams()[self::COOKIE_NAME] ?? null;

        if (!is_string($token)) {
            return $this->unauthenticated($request);
        }

        $session = $this->sessions->find($token, $request->getHeaderLine('User-Agent'));

        if ($session === null) {
            return $this->unauthenticated($request);
        }

        $context = $session->principal->context(
            $this->site ?? SiteContext::default(),
            AuthenticationStrength::Password,
            $this->requestId($request),
        );
        try {
            $this->authorization->assertAllowed(
                $context,
                Capability::fromString('administrator.access'),
                AuthorizationResource::item('administrator_session', $session->id),
            );
        } catch (AuthorizationDenied) {
            return new JsonResponse([
                'type' => 'about:blank',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'Administrator access is not granted for this session.',
            ], 403, ['Content-Type' => 'application/problem+json']);
        }

        return $handler->handle(
            $request
                ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
                ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $session->principal)
                ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context),
        );
    }

    private function unauthenticated(ServerRequestInterface $request): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return new RedirectResponse('/administrator/login', 303, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'A valid administrator session is required.',
        ], 401, ['Content-Type' => 'application/problem+json']);
    }

    private function requestId(ServerRequestInterface $request): string
    {
        $requestId = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($requestId) && $requestId !== ''
            ? $requestId
            : 'request-' . bin2hex(random_bytes(16));
    }
}
