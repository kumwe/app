<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use InvalidArgumentException;
use Kumwe\App\Content\Application\ContentRecord;

/**
 * Builds the same-origin, digest-addressed URL for one live published Producer stylesheet.
 *
 * The URL carries only lookup coordinates. The stylesheet endpoint treats none of them as authority:
 * it re-resolves publication, site, canonical path, locale, entry identity, renderer trust and exact
 * bytes before serving or validating a cache response.
 *
 * @since  2.0.0
 */
final class StudioPublishedStylesheet
{
    /**
     * Public route prefix owned by the App delivery layer.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PATH_PREFIX = '/studio/styles/';

    /**
     * Build one root-relative stylesheet URL whose path identity is the exact CSS digest.
     *
     * @param   ContentRecord  $record    Published record the stylesheet belongs to.
     * @param   string         $pagePath  Canonical public page path the browser requested.
     * @param   string         $css       Exact Producer stylesheet bytes.
     *
     * @return  string  Root-relative digest-addressed stylesheet URL.
     *
     * @throws  InvalidArgumentException  When a trusted caller supplies an unusable path or CSS value.
     *
     * @since   2.0.0
     */
    public static function href(ContentRecord $record, string $pagePath, string $css): string
    {
        if (
            $pagePath === ''
            || strlen($pagePath) > 2048
            || $pagePath[0] !== '/'
            || str_contains($pagePath, "\0")
            || str_contains($pagePath, '?')
            || str_contains($pagePath, '#')
        ) {
            throw new InvalidArgumentException('A published Studio stylesheet page path is invalid.');
        }
        if ($css === '' || strlen($css) > 16_777_215 || !mb_check_encoding($css, 'UTF-8')) {
            throw new InvalidArgumentException('A published Studio stylesheet is invalid.');
        }
        $digest = self::digest($css);
        $query = http_build_query([
            'page' => $pagePath,
            'entry' => $record->entry->id(),
            'locale' => $record->entry->locale()?->toString() ?? 'und',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::PATH_PREFIX . $digest . '.css?' . $query;
    }

    /**
     * Return the lowercase SHA-256 cache identity of exact stylesheet bytes.
     *
     * @param   string  $css  Exact stylesheet bytes.
     *
     * @return  string  Lowercase SHA-256 hex digest.
     *
     * @since   2.0.0
     */
    public static function digest(string $css): string
    {
        return hash('sha256', $css);
    }

    /**
     * Static URL grammar only; no instance carries state.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
