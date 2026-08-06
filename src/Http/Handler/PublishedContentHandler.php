<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Presentation\SiteRenderer;
use Kumwe\CMS\Presentation\RichTextFormatter;
use Kumwe\CMS\Navigation\Application\PublicNavigation;
use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PublishedContentHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentService $content,
        private SiteSettings $settings,
        private SiteRenderer $renderer,
        private RichTextFormatter $richText,
        private ?SiteContext $site = null,
        private ?PublicNavigation $navigation = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $slug = $request->getAttribute('slug');

        if (!is_string($slug) || $slug === '') {
            return $this->notFound();
        }

        $record = $this->content->publishedBySlug($slug, $this->site ?? SiteContext::default());

        if ($record === null) {
            return $this->notFound();
        }

        $settings = $this->settings->current();
        $headers = ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=300'];
        if ($settings['search_indexing_enabled'] !== true) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        }

        return new HtmlResponse(
            $this->renderer->render('page', [
                'site_name' => $settings['site_name'],
                'entry' => $this->present($record->toArray()),
                'navigation' => $this->navigation?->items() ?? [],
                'current_path' => '/pages/' . $slug,
            ]),
            200,
            $headers,
        );
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function present(array $entry): array
    {
        $data = $entry['data'] ?? null;
        $body = is_array($data) && is_string($data['body'] ?? null) ? $data['body'] : '';
        $entry['body_html'] = $this->richText->format($body);

        return $entry;
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
