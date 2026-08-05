<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Presentation\SiteRenderer;
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
        private ?SiteContext $site = null,
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

        $headers = ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=300'];
        if ($this->settings->current()['search_indexing_enabled'] !== true) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        }

        return new HtmlResponse(
            $this->renderer->render('page', ['entry' => $record->toArray()]),
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
