<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorSettingsHandler implements RequestHandlerInterface
{
    public function __construct(private SiteSettings $settings, private AdministratorRenderer $renderer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);

        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $this->settings->update(
                $session->principal->subject(),
                AdministratorRequest::required($form, 'site_name'),
                AdministratorRequest::required($form, 'homepage_slug'),
            );

            return new RedirectResponse('/administrator/settings?saved=1', 303);
        }

        return new HtmlResponse($this->renderer->render('settings', [
            'csrf' => $session->csrfToken,
            'settings' => $this->settings->current(),
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
        ]), 200, ['Cache-Control' => 'no-store']);
    }
}
