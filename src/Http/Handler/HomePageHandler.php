<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Presentation\ContentPresenter;
use Kumwe\CMS\Presentation\SiteRenderer;
use Kumwe\CMS\Site\Application\PublicPageLocator;
use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class HomePageHandler implements RequestHandlerInterface
{
    public function __construct(
        private PublicPageLocator $pages,
        private SiteSettings $settings,
        private SiteRenderer $renderer,
        private ContentPresenter $presenter,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $settings = $this->settings->current();
        $record = $this->pages->homepage();
        $template = $record === null ? 'home' : 'page';
        $entry = $record === null ? null : $this->presenter->present($record);
        $variables = $record === null
            ? ['site_name' => $settings['site_name']]
            : ['site_name' => $settings['site_name'], 'entry' => $entry];
        $brandLogo = is_array($entry) && is_array($entry['data'] ?? null)
            ? ($entry['data']['brand_logo'] ?? null)
            : null;
        $variables['site_logo'] = is_string($brandLogo) ? $brandLogo : null;
        $variables['navigation'] = $this->pages->navigation();
        $variables['current_path'] = '/';
        $variables['canonical_url'] = '/';

        $headers = [
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=300',
        ];
        if ($settings['search_indexing_enabled'] !== true) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        }

        return new HtmlResponse($this->renderer->render($template, $variables), 200, $headers);
    }
}
