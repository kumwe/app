<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation;

use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocation;

/**
 * Canonical site-template and theme path shared by published and unpublished content rendering.
 *
 * @since  2.0.0
 */
final readonly class ContentPageRenderService
{
    /**
     * Bind the canonical page path to validated settings and the isolated site Twig renderer.
     *
     * @param  SiteSettings  $settings  Site presentation document.
     * @param  SiteRenderer  $renderer  Isolated site template/theme renderer.
     *
     * @since  2.0.0
     */
    public function __construct(private SiteSettings $settings, private SiteRenderer $renderer)
    {
    }

    /**
     * Path of the same-origin stylesheet that carries a site's validated palette to its public pages.
     *
     * The palette used to travel as a `style` attribute on `<body>`, which is the one thing that kept
     * `style-src-attr 'unsafe-inline'` in the content-security policy. It now travels as this
     * stylesheet, served by `SitePresentationStylesheetHandler` and linked from the layout's `<head>`,
     * so every public response is covered by `style-src 'self'` alone.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string THEME_STYLESHEET_PATH = '/presentation/theme.css';

    /**
     * Build the CSS text that installs the active palette as custom properties on `<body>`.
     *
     * The text is derived from the same settings snapshot and the same per-menu scheme override the
     * page itself was rendered with, so the stylesheet a page links always says what its `<body>`
     * used to carry inline. Every property name and value is validated again here, because the text
     * is served as CSS and nothing between the settings store and the browser may loosen it.
     *
     * @param   ?string  $schemeOverride  Menu-bound colour scheme handle, or null for the site default.
     *
     * @return  string  A single `body{...}` rule of validated `--site-*` declarations.
     *
     * @throws  \InvalidArgumentException  When the stored palette carries a property or value outside
     *          the validated grammar.
     *
     * @since   2.0.0
     */
    public function themeStylesheet(?string $schemeOverride): string
    {
        $settings = $this->settings->current();
        $presentation = SitePresentation::from(
            $settings['presentation'] ?? SitePresentation::defaults(),
        )->withSchemeOverride($schemeOverride)->toView();

        return self::themeDeclarations($presentation['css_variables']);
    }

    /**
     * Build the link a page uses to fetch its theme stylesheet, versioned by the stylesheet's own digest.
     *
     * The digest in the query lets the stylesheet handler answer with a long immutable cache lifetime
     * whenever the link and the served text agree, while a palette change rewrites every page's link
     * and so reaches the browser on its next page load. A scheme override is named only when it is a
     * well-formed handle; anything else could never match a stored scheme and so renders the default
     * palette, which is exactly what omitting it serves.
     *
     * @param   ?string  $schemeOverride  Menu-bound colour scheme handle, or null for the site default.
     * @param   string   $stylesheet      The CSS text `themeStylesheet()` produced for that override.
     *
     * @return  string  Same-origin path with `scheme` and `v` query parameters.
     *
     * @since   2.0.0
     */
    public static function themeStylesheetHref(?string $schemeOverride, string $stylesheet): string
    {
        $query = [];
        if (is_string($schemeOverride) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $schemeOverride) === 1) {
            $query['scheme'] = $schemeOverride;
        }
        $query['v'] = hash('sha256', $stylesheet);

        return self::THEME_STYLESHEET_PATH . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Turn the validated `css_variables` map into one `body{...}` rule, refusing anything off-grammar.
     *
     * @param   mixed  $variables  The `css_variables` entry of a presentation view.
     *
     * @return  string  `body{` followed by `--name:#rrggbb;` declarations and `}`.
     *
     * @throws  \InvalidArgumentException  When the map is not a keyed array, or a property or value
     *          falls outside the validated grammar.
     *
     * @since   2.0.0
     */
    private static function themeDeclarations(mixed $variables): string
    {
        if (!is_array($variables) || array_is_list($variables)) {
            throw new \InvalidArgumentException('The generated theme style variables are invalid.');
        }
        $declarations = '';
        foreach ($variables as $property => $value) {
            if (
                !is_string($property)
                || preg_match('/^--[a-z0-9-]+$/D', $property) !== 1
                || !is_string($value)
                || preg_match('/^#[a-f0-9]{6}$/D', $value) !== 1
            ) {
                throw new \InvalidArgumentException('A generated theme style variable is invalid.');
            }
            $declarations .= $property . ':' . $value . ';';
        }

        return 'body{' . $declarations . '}';
    }

    /**
     * Render one content page through the same validated site presentation pipeline.
     *
     * @param   string                       $template               Site layout name without `.twig`.
     * @param   array<string, mixed>|null    $entry                  Already presented safe entry, or null for a home
     *          template with no selected record.
     * @param   string                       $currentPath            Current application path.
     * @param   string                       $canonicalUrl           Canonical path or absolute URL.
     * @param   string|null                  $schemeOverride         Optional menu-bound colour scheme.
     * @param   string                       $surfaceId              Stable interface surface identity.
     * @param   list<array<string, mixed>>   $navigation             Presented site navigation.
     * @param   array<string, mixed>         $languages              Presented language alternates.
     * @param   bool                         $includeThemeVariables  Whether validated CSS variables may be emitted as
     *          the existing public theme attribute; preview documents set false under their stricter CSP.
     * @param   string|null                  $studioStylesheetHref   Exact same-origin Producer stylesheet URL.
     * @param   ?StudioBrowserAssetLocation  $studioEnhancement      Pinned enhancement runtime the rendered blocks
     *          need, deferred with its manifest integrity, or null for a script-free page.
     *
     * @return  string  Complete themed HTML document.
     *
     * @since   2.0.0
     */
    public function render(
        string $template,
        ?array $entry,
        string $currentPath,
        string $canonicalUrl,
        ?string $schemeOverride,
        string $surfaceId,
        array $navigation = [],
        array $languages = [],
        bool $includeThemeVariables = true,
        ?string $studioStylesheetHref = null,
        ?StudioBrowserAssetLocation $studioEnhancement = null,
    ): string {
        $settings = $this->settings->current();
        $presentation = SitePresentation::from(
            $settings['presentation'] ?? SitePresentation::defaults(),
        )->withSchemeOverride($schemeOverride)->toView();
        if ($includeThemeVariables) {
            $presentation['theme_stylesheet'] = self::themeStylesheetHref(
                $schemeOverride,
                self::themeDeclarations($presentation['css_variables']),
            );
        } else {
            $presentation['css_variables'] = [];
        }

        $variables = [
            'site_name' => $settings['site_name'],
            'navigation' => $navigation,
            'current_path' => $currentPath,
            'canonical_url' => $canonicalUrl,
            'site_logo' => $presentation['logo'],
            'presentation' => $presentation,
            'surface_id' => $surfaceId,
            'languages' => $languages,
        ];
        if ($entry !== null) {
            $variables['entry'] = $entry;
        }

        $html = $this->renderer->render($template, $variables);
        if ($studioStylesheetHref === null) {
            return $html;
        }
        if (
            preg_match(
                '/^\/studio\/styles\/[a-f0-9]{64}\.css\?[A-Za-z0-9._~%=&-]{1,4096}$/D',
                $studioStylesheetHref,
            ) !== 1
            || str_contains($studioStylesheetHref, '..')
        ) {
            throw new \InvalidArgumentException('The published Studio stylesheet URL is invalid.');
        }
        $offset = strripos($html, '</head>');
        if ($offset === false) {
            throw new \InvalidArgumentException('A themed content document must contain a closing head tag.');
        }
        $link = sprintf(
            '<link rel="stylesheet" href="%s" data-studio-composition>',
            htmlspecialchars($studioStylesheetHref, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
        );
        if ($studioEnhancement !== null) {
            $link .= sprintf(
                '<script defer src="%s" integrity="%s" crossorigin="anonymous" data-studio-enhancements></script>',
                htmlspecialchars($studioEnhancement->url(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
                htmlspecialchars($studioEnhancement->integrity(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            );
        }

        return substr_replace($html, $link, $offset, 0);
    }

    /**
     * Render an exact preview document whose validated theme travels through a same-origin stylesheet.
     *
     * @param   string                     $template        Site layout name without `.twig`.
     * @param   array<string, mixed>|null  $entry           Already presented safe entry.
     * @param   string                     $currentPath     Current application path.
     * @param   string                     $canonicalUrl    Canonical path or absolute URL.
     * @param   string|null                $schemeOverride  Optional menu-bound colour scheme.
     * @param   string                     $surfaceId       Stable interface surface identity.
     * @param   string                     $stylesheetHref  Trusted combined stylesheet sentinel.
     *
     * @return  array{html: string, themeStylesheet: string}  Complete HTML and its closed theme stylesheet.
     *
     * @since   2.0.0
     */
    public function renderPreview(
        string $template,
        ?array $entry,
        string $currentPath,
        string $canonicalUrl,
        ?string $schemeOverride,
        string $surfaceId,
        string $stylesheetHref,
    ): array {
        if (
            preg_match('/^\/[A-Za-z0-9._\/-]{1,255}$/D', $stylesheetHref) !== 1
            || str_contains($stylesheetHref, '..')
        ) {
            throw new \InvalidArgumentException('The preview stylesheet path is invalid.');
        }
        $settings = $this->settings->current();
        $presentation = SitePresentation::from(
            $settings['presentation'] ?? SitePresentation::defaults(),
        )->withSchemeOverride($schemeOverride)->toView();
        $themeStylesheet = self::themeDeclarations($presentation['css_variables']);
        $presentation['css_variables'] = [];
        $variables = [
            'site_name' => $settings['site_name'],
            'navigation' => [],
            'current_path' => $currentPath,
            'canonical_url' => $canonicalUrl,
            'site_logo' => $presentation['logo'],
            'presentation' => $presentation,
            'surface_id' => $surfaceId,
            'languages' => [],
        ];
        if ($entry !== null) {
            $variables['entry'] = $entry;
        }
        $html = $this->renderer->render($template, $variables);
        $offset = strripos($html, '</head>');
        if ($offset === false) {
            throw new \InvalidArgumentException('A themed content document must contain a closing head tag.');
        }
        $link = sprintf('<link rel="stylesheet" href="%s" data-studio-composition>', $stylesheetHref);
        $html = substr_replace($html, $link, $offset, 0);

        return ['html' => $html, 'themeStylesheet' => $themeStylesheet];
    }

    /**
     * Report whether published pages may be indexed, from the same effective settings snapshot.
     *
     * @return  bool  True only for an explicit enabled setting.
     *
     * @since   2.0.0
     */
    public function searchIndexingEnabled(): bool
    {
        return $this->settings->current()['search_indexing_enabled'] === true;
    }
}
