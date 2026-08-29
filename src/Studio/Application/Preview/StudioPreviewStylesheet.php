<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use InvalidArgumentException;

/**
 * Activates the one same-origin combined Producer and theme stylesheet link in a claimed preview.
 *
 * @since  2.0.0
 */
final class StudioPreviewStylesheet
{
    /**
     * Trusted renderer sentinel, never sent to a browser.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string HREF_PLACEHOLDER = '/__kumwe_studio_preview_styles__.css';

    /**
     * Replace the trusted link sentinel with its authenticated, grant-bound URL.
     *
     * @param   string  $html      Trusted rendered preview document.
     * @param   string  $href      Root-relative authenticated stylesheet URL.
     * @param   bool    $required  Whether the rendered grant carries a combined stylesheet.
     *
     * @return  string  Activated document bytes.
     *
     * @throws  InvalidArgumentException  When the trusted link inventory is incoherent.
     *
     * @since   2.0.0
     */
    public static function activate(string $html, string $href, bool $required): string
    {
        if (
            preg_match('/^\/administrator\/studio\/preview\/styles\.css\?[A-Za-z0-9._~%=&-]{1,2048}$/D', $href) !== 1
        ) {
            throw new InvalidArgumentException('The Studio preview stylesheet URL is invalid.');
        }
        $token = sprintf('href="%s"', self::HREF_PLACEHOLDER);
        $count = substr_count($html, $token);
        if (($required && $count !== 1) || (!$required && $count !== 0)) {
            throw new InvalidArgumentException('The Studio preview stylesheet inventory is invalid.');
        }

        return $required ? str_replace($token, sprintf('href="%s"', $href), $html) : $html;
    }

    /**
     * Prevent construction of this stateless trusted-link activator.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
