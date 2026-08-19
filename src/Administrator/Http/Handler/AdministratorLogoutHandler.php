<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Ends the current administrator session and clears the cookie that pointed at it.
 *
 * Signing out has to happen in two places at once to mean anything: the stored session is deleted so a
 * token someone copied is no longer redeemable, and the cookie is expired so this browser stops
 * presenting it. Doing only the second would leave a live session behind. The route is a `POST` behind
 * the CSRF middleware for that reason — a sign-out has to be a deliberate act, not something a link
 * can trigger.
 *
 * @since  2.0.0
 */
final readonly class AdministratorLogoutHandler implements RequestHandlerInterface
{
    /**
     * Wire the sign-out route to the session store and the cookie policy it must mirror.
     *
     * @param  AdministratorSessionStore  $sessions      Store the session row is deleted from.
     * @param  bool                       $secureCookie  Whether the expiring cookie carries `Secure`; it must
     *         match what the sign-in set or the browser keeps the old one.
     *
     * @since  2.0.0
     */
    public function __construct(private AdministratorSessionStore $sessions, private bool $secureCookie)
    {
    }

    /**
     * Delete the signed-in session and answer with a cookie that expires the browser's copy.
     *
     * The clearing cookie repeats every attribute the sign-in used — name, path, `HttpOnly`,
     * `SameSite` and `Secure` — because a browser only replaces a cookie whose attributes match; a
     * mismatch would leave the original in place until it expired on its own.
     *
     * @param   ServerRequestInterface  $request  Administrator request, already authenticated and CSRF-checked.
     *
     * @return  ResponseInterface  A 303 to the sign-in screen with the session cookie expired.
     *
     * @throws  \InvalidArgumentException  When the route was mounted without administrator session middleware.
     *
     * @since   2.0.0
     */
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
