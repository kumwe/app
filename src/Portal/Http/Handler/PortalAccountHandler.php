<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Portal\Http\Middleware\PortalSessionMiddleware;
use Kumwe\App\Portal\Http\PortalRequest;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Exposes self-service password replacement through the ordinary-user portal's own authority boundary.
 *
 * @since  2.0.0
 */
final readonly class PortalAccountHandler implements RequestHandlerInterface
{
    /**
     * Compose the shared credential use case with isolated portal rendering and cookie policy.
     *
     * @param  AccessControlService  $access        Existing credential lifecycle authority.
     * @param  PortalRenderer        $renderer      Portal shell and localized form.
     * @param  bool                  $secureCookie  Whether the deployment uses HTTPS.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlService $access,
        private PortalRenderer $renderer,
        private bool $secureCookie,
    ) {
    }

    /**
     * Change only the authenticated actor's credential; never accept a target identity from the form.
     *
     * @param   ServerRequestInterface  $request  Authenticated request; POST has passed portal CSRF.
     *
     * @return  ResponseInterface  No-store form or refusal, or redirect after revoking all previous credentials.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $error = null;
        $status = 200;
        if ($request->getMethod() === 'POST') {
            $form = PortalRequest::form($request);
            if (!hash_equals($form['new_password'] ?? '', $form['new_password_confirmation'] ?? '')) {
                $error = 'core.identity.password.confirmation_mismatch';
                $status = 422;
            } else {
                try {
                    $this->access->changeOwnPassword(
                        PortalRequest::context($request),
                        $form['current_password'] ?? '',
                        $form['new_password'] ?? '',
                    );

                    return new RedirectResponse('/portal/login?password_changed=1', 303, [
                        'Cache-Control' => 'no-store',
                        'Set-Cookie' => sprintf(
                            '%s=; Path=/portal; Max-Age=0; HttpOnly; SameSite=Strict%s',
                            PortalSessionMiddleware::COOKIE_NAME,
                            $this->secureCookie ? '; Secure' : '',
                        ),
                    ]);
                } catch (AuthenticationThrottled) {
                    $error = 'core.security.authentication.throttled';
                    $status = 429;
                } catch (HighImpactAuthenticationRequired | InvalidArgumentException) {
                    $error = 'core.identity.password.change_refused';
                    $status = 422;
                }
            }
        }

        $headers = ['Cache-Control' => 'no-store'];
        if ($status === 429) {
            $headers['Retry-After'] = '900';
        }

        return new HtmlResponse($this->renderer->render('account', [
            'error_key' => $error,
        ], $session), $status, $headers);
    }
}
