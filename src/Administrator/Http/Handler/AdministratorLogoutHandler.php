<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorLogoutHandler implements RequestHandlerInterface
{
    public function __construct(private AdministratorSessionStore $sessions, private bool $secureCookie)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->sessions->delete(
            AdministratorRequest::context($request),
            AdministratorRequest::session($request)->id,
        );

        return new RedirectResponse('/administrator/login', 303, [
            'Cache-Control' => 'no-store',
            'Set-Cookie' => sprintf(
                '%s=deleted; Path=/administrator; Max-Age=0; HttpOnly; SameSite=Strict%s',
                AdministratorSessionMiddleware::COOKIE_NAME,
                $this->secureCookie ? '; Secure' : '',
            ),
        ]);
    }
}
