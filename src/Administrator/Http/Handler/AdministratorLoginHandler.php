<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorLoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdministratorIdentityGateway $identities,
        private AdministratorSessionStore $sessions,
        private AdministratorRenderer $renderer,
        private bool $secureCookie,
        private int $sessionLifetime,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            return $this->form();
        }

        $form = AdministratorRequest::form($request);

        try {
            $principal = $this->identities->authenticate(
                $form['email'] ?? '',
                $form['password'] ?? '',
                (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'),
            );
        } catch (AuthenticationThrottled $exception) {
            return new HtmlResponse($this->renderer->render('login', [
                'error' => $exception->getMessage(),
                'email' => $form['email'] ?? '',
            ]), 429, ['Cache-Control' => 'no-store', 'Retry-After' => '900']);
        }

        if ($principal === null) {
            return new HtmlResponse($this->renderer->render('login', [
                'error' => 'The email address or password is incorrect.',
                'email' => $form['email'] ?? '',
            ]), 401, ['Cache-Control' => 'no-store']);
        }

        $created = $this->sessions->create($principal, $request->getHeaderLine('User-Agent'));

        return new RedirectResponse('/administrator', 303, [
            'Cache-Control' => 'no-store',
            'Set-Cookie' => $this->cookie($created->token),
        ]);
    }

    private function form(): ResponseInterface
    {
        return new HtmlResponse(
            $this->renderer->render('login'),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    private function cookie(string $token): string
    {
        return sprintf(
            '%s=%s; Path=/administrator; Max-Age=%d; HttpOnly; SameSite=Strict%s',
            AdministratorSessionMiddleware::COOKIE_NAME,
            $token,
            $this->sessionLifetime,
            $this->secureCookie ? '; Secure' : '',
        );
    }
}
