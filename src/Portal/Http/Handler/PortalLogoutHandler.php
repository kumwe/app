<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Http\Handler;

use Kumwe\CMS\Portal\Application\PortalSessionStore;
use Kumwe\CMS\Portal\Http\Middleware\PortalSessionMiddleware;
use Kumwe\CMS\Portal\Http\PortalRequest;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Revokes only the active portal session and clears only the portal cookie.
 *
 * @since  2.0.0
 */
final readonly class PortalLogoutHandler implements RequestHandlerInterface
{
    /**
     * Bind logout to portal storage and cookie transport policy.
     *
     * @param  PortalSessionStore  $sessions      Dedicated portal store.
     * @param  bool                $secureCookie  Whether the expiring cookie repeats `Secure`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PortalSessionStore $sessions,
        private bool $secureCookie,
    ) {
    }

    /**
     * Revoke the authenticated portal session after outer portal CSRF validation.
     *
     * @param   ServerRequestInterface  $request  Authenticated portal POST.
     *
     * @return  ResponseInterface  303 login redirect with an expiring portal-scoped cookie.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $this->sessions->delete($session->id, $session->identity->principal->subject());

        return new RedirectResponse('/portal/login', 303, [
            'Cache-Control' => 'no-store',
            'Set-Cookie' => sprintf(
                '%s=; Path=/portal; Max-Age=0; HttpOnly; SameSite=Strict%s',
                PortalSessionMiddleware::COOKIE_NAME,
                $this->secureCookie ? '; Secure' : '',
            ),
        ]);
    }
}
