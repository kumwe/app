<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Presentation\ContentPresenter;
use Kumwe\CMS\Presentation\SiteRenderer;
use Kumwe\CMS\Site\Application\PublicPageLocator;
use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PublishedContentHandler implements RequestHandlerInterface
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
        $requestedPath = $request->getUri()->getPath();
        $record = $this->pages->byPath($requestedPath);

        if ($record === null) {
            return $this->notFound();
        }

        $canonicalPath = $this->pages->pathFor($record);
        if ($requestedPath !== $canonicalPath) {
            $query = $request->getUri()->getQuery();

            return new RedirectResponse(
                $canonicalPath . ($query === '' ? '' : '?' . $query),
                308,
                ['Cache-Control' => 'public, max-age=300'],
            );
        }

        $settings = $this->settings->current();
        $headers = ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=300'];
        if ($settings['search_indexing_enabled'] !== true) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        }
        $homepage = $this->pages->homepage();
        $homepageEntry = $homepage === null ? null : $this->presenter->present($homepage);
        $brandLogo = is_array($homepageEntry) && is_array($homepageEntry['data'] ?? null)
            ? ($homepageEntry['data']['brand_logo'] ?? null)
            : null;

        return new HtmlResponse(
            $this->renderer->render('page', [
                'site_name' => $settings['site_name'],
                'entry' => $this->presenter->present($record),
                'navigation' => $this->pages->navigation(),
                'current_path' => $canonicalPath,
                'canonical_url' => $canonicalPath,
                'site_logo' => is_string($brandLogo) ? $brandLogo : null,
            ]),
            200,
            $headers,
        );
    }

    private function notFound(): ResponseInterface
    {
        return new HtmlResponse(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Not found</title></head>'
            . '<body><main><h1>Page not found</h1></main></body></html>',
            404,
            ['Cache-Control' => 'no-store'],
        );
    }
}
