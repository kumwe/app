<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Presentation\Application\SitePresentation;
use Kumwe\CMS\Presentation\ContentPresenter;
use Kumwe\CMS\Presentation\SiteRenderer;
use Kumwe\CMS\Site\Application\PublicPageLocator;
use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves published content at whatever public path the site's navigation gives it.
 *
 * This is the catch-all of the public site: it takes the requested path, asks `PublicPageLocator` which
 * published record — if any — lives there, and renders it. Because one record is reachable by several
 * paths (its slug, its position in a menu, `/pages/{slug}`, or `/` when it is the homepage), the handler
 * also owns canonicalisation. A request that arrives on a non-canonical path is answered with a
 * permanent 308 to the canonical one, query string intact, so links, caches, and search results converge
 * on a single URL per page rather than splitting across duplicates.
 *
 * Missing, unpublished, and reserved paths all produce the same minimal HTML 404, which keeps the
 * existence of draft, scheduled, or trashed content out of the public response.
 *
 * @since  2.0.0
 */
final readonly class PublishedContentHandler implements RequestHandlerInterface
{
    /**
     * Bind the public content route to the locator, settings, and rendering collaborators it composes.
     *
     * @param  PublicPageLocator  $pages      Resolver that maps a request path to a published record and
     *         reports that record's canonical path.
     * @param  SiteSettings       $settings   Source of the site name, presentation contract, and the
     *         search-indexing switch.
     * @param  SiteRenderer       $renderer   Site template renderer that produces the HTML body.
     * @param  ContentPresenter   $presenter  Presenter that escapes and renders the record's stored
     *         bodies before they reach a template.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PublicPageLocator $pages,
        private SiteSettings $settings,
        private SiteRenderer $renderer,
        private ContentPresenter $presenter,
    ) {
    }

    /**
     * Resolves the request path to a published record and renders it, redirects to it, or refuses it.
     *
     * Canonicalisation is settled before anything is rendered, so a page is only ever built on its
     * canonical path and the `current_path` and `canonical_url` view variables can never disagree.
     * As on the front page, an `X-Robots-Tag` refusal is added whenever site indexing is switched off.
     *
     * @param   ServerRequestInterface  $request  Request whose URI path selects the record, and whose
     *          query string is carried across a canonical redirect.
     *
     * @return  ResponseInterface  A 200 HTML page when the path is already canonical, a 308 redirect to
     *          the canonical path when it is not, or an uncacheable 404 page when nothing is published
     *          there.
     *
     * @throws  \InvalidArgumentException  When the stored presentation settings are not a valid contract.
     * @throws  \RuntimeException  When the asset manifest cannot be read or names no files for the site
     *          entry point.
     *
     * @since   2.0.0
     */
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
        $presentation = SitePresentation::from(
            $settings['presentation'] ?? SitePresentation::defaults(),
        )->toView();

        return new HtmlResponse(
            $this->renderer->render('page', [
                'site_name' => $settings['site_name'],
                'entry' => $this->presenter->present($record),
                'navigation' => $this->pages->navigation(),
                'current_path' => $canonicalPath,
                'canonical_url' => $canonicalPath,
                'site_logo' => $presentation['logo'],
                'presentation' => $presentation,
            ]),
            200,
            $headers,
        );
    }

    /**
     * Builds the uncacheable miss page returned for any path that resolves to nothing published.
     *
     * The markup is inline rather than rendered through Twig so that a miss cannot itself fail on
     * template or theme resolution, and it is deliberately identical for missing, unpublished, and
     * reserved paths so the response never distinguishes between them.
     *
     * @return  ResponseInterface  A 404 HTML response marked `no-store`.
     *
     * @since   2.0.0
     */
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
