<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twig\Environment;

final readonly class HomePageHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentService $content,
        private SiteSettings $settings,
        private Environment $twig,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $settings = $this->settings->current();
        $record = $this->content->publishedBySlug($settings['homepage_slug']);
        $template = $record === null ? 'site/home.twig' : 'site/page.twig';
        $variables = $record === null
            ? ['site_name' => $settings['site_name']]
            : ['site_name' => $settings['site_name'], 'entry' => $record->toArray()];

        return new HtmlResponse($this->twig->render($template, $variables), 200, [
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=300',
        ]);
    }
}
