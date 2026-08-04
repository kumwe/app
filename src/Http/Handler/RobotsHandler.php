<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RobotsHandler implements RequestHandlerInterface
{
    public function __construct(private SiteSettings $settings)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $enabled = $this->settings->current()['search_indexing_enabled'] === true;
        $body = $enabled ? "User-agent: *\nAllow: /\n" : "User-agent: *\nDisallow: /\n";

        return new TextResponse($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
