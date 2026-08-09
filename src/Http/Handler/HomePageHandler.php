<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Presentation\Application\SitePresentation;
use Kumwe\CMS\Presentation\ContentPresenter;
use Kumwe\CMS\Presentation\SiteRenderer;
use Kumwe\CMS\Site\Application\PublicPageLocator;
use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders the public front page at `/`, whether or not an operator has nominated a homepage.
 *
 * The front page is a setting rather than a fixed record, so this handler asks `PublicPageLocator` which
 * published entry the site currently points at. When nothing is nominated it falls back to the standalone
 * `home` template instead of returning a 404, which is what lets a freshly installed site serve a usable
 * page before any content exists. Everything else in the response — navigation, branding, the caching
 * and indexing headers — matches `PublishedContentHandler`, so a visitor cannot tell from the response
 * whether the front page is a nominated entry or the fallback.
 *
 * @since  2.0.0
 */
final readonly class HomePageHandler implements RequestHandlerInterface
{
    /**
     * Bind the front page to the locator, settings, and rendering collaborators it composes.
     *
     * @param  PublicPageLocator  $pages      Resolver for the nominated homepage record and the site's
     *         public navigation tree.
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
     * Builds the front page for a request to `/`.
     *
     * The page is cached publicly for a minute with a five-minute stale-while-revalidate window, and
     * carries an `X-Robots-Tag` refusal whenever the site has search indexing switched off — which is
     * how a staging deployment stays out of search results without a separate configuration path.
     *
     * @param   ServerRequestInterface  $request  Incoming request; the front page takes no input from it.
     *
     * @return  ResponseInterface  A 200 HTML response rendered from the `page` template when a homepage
     *          entry is nominated, and from the `home` template otherwise.
     *
     * @throws  \InvalidArgumentException  When the stored presentation settings are not a valid contract.
     * @throws  \RuntimeException  When the asset manifest cannot be read or names no files for the site
     *          entry point.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $settings = $this->settings->current();
        $record = $this->pages->homepage();
        $template = $record === null ? 'home' : 'page';
        $entry = $record === null ? null : $this->presenter->present($record);
        $presentation = SitePresentation::from(
            $settings['presentation'] ?? SitePresentation::defaults(),
        )->toView();
        $variables = $record === null
            ? ['site_name' => $settings['site_name'], 'presentation' => $presentation]
            : ['site_name' => $settings['site_name'], 'entry' => $entry, 'presentation' => $presentation];
        $variables['site_logo'] = $presentation['logo'];
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
