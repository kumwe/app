<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets any authenticated administrator replace their own password without identity-management authority.
 *
 * The subject comes only from the resolved session. Current-password verification, rate limiting,
 * password policy, epoch revocation and audit remain the existing identity application's responsibility.
 *
 * @since  2.0.0
 */
final readonly class AdministratorAccountHandler implements RequestHandlerInterface
{
    /**
     * Compose self-service identity changes with the administrator renderer and cookie policy.
     *
     * @param  AccessControlService   $access        Existing credential lifecycle authority.
     * @param  AdministratorRenderer  $renderer      Administrator shell and localized form.
     * @param  bool                   $secureCookie  Whether the deployment uses HTTPS.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlService $access,
        private AdministratorRenderer $renderer,
        private bool $secureCookie,
    ) {
    }

    /**
     * Render a secret-free form or change only the session owner's password and sign out.
     *
     * @param   ServerRequestInterface  $request  Authenticated request; POST has passed administrator CSRF.
     *
     * @return  ResponseInterface  No-store account form, safe refusal, or sign-in redirect.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $error = null;
        $status = 200;
        if ($request->getMethod() === 'POST') {
            $form = AdministratorRequest::form($request);
            if (!hash_equals($form['new_password'] ?? '', $form['new_password_confirmation'] ?? '')) {
                $error = 'core.identity.password.confirmation_mismatch';
                $status = 422;
            } else {
                try {
                    $this->access->changeOwnPassword(
                        AdministratorRequest::context($request),
                        $form['current_password'] ?? '',
                        $form['new_password'] ?? '',
                    );

                    return new RedirectResponse('/administrator/login?password_changed=1', 303, [
                        'Cache-Control' => 'no-store',
                        'Set-Cookie' => sprintf(
                            '%s=; Path=/administrator; Max-Age=0; HttpOnly; SameSite=Strict%s',
                            AdministratorSessionMiddleware::COOKIE_NAME,
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
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'error_key' => $error,
        ]), $status, $headers);
    }
}
