<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves `/robots.txt` from the live site settings rather than from a file on disk.
 *
 * Whether a site may be indexed is an operator decision that changes between staging, launch and
 * takedown, so it belongs to the settings an operator edits and not to a deployed asset. This handler
 * reads `search_indexing_enabled` on every request and emits the matching blanket rule, which lets a
 * site be opened or closed to crawlers without a redeploy. The short cache lifetime bounds how long a
 * flipped setting keeps serving the previous answer from an intermediary.
 *
 * @since  2.0.0
 */
final readonly class RobotsHandler implements RequestHandlerInterface
{
    /**
     * Wire the handler to the settings source it consults on each request.
     *
     * @param  SiteSettings  $settings  Live site settings supplying the `search_indexing_enabled` flag.
     *
     * @since  2.0.0
     */
    public function __construct(private SiteSettings $settings)
    {
    }

    /**
     * Render the crawler directive that matches the current indexing setting.
     *
     * Indexing is opened only when the setting is boolean `true`; every other value closes the site
     * with `Disallow: /`, so a half-configured or freshly installed site fails closed.
     *
     * @param   ServerRequestInterface  $request  Incoming request; nothing in it varies the response.
     *
     * @return  ResponseInterface  A 200 `text/plain` response holding one blanket crawler rule.
     *
     * @since   2.0.0
     */
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
