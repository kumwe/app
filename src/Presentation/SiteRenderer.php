<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation;

use Kumwe\CMS\Presentation\Asset\ViteAssetManifest;
use Kumwe\CMS\Presentation\Twig\SiteTwigEnvironment;

/**
 * Renders a public site template through the site Twig environment with the site-wide view data applied.
 *
 * Public HTTP handlers assemble page data and call this; they never touch Twig directly. The renderer
 * owns the two things every site page needs and no handler should repeat: the built stylesheet and
 * module lists for the site entry point, and the absolute form of the canonical URL. Because the Twig
 * environment it holds is the isolated site one, a template resolved here can only reach site templates
 * and the active site theme.
 *
 * @since  2.0.0
 */
final readonly class SiteRenderer
{
    /**
     * Bind the renderer to the site template environment and the deployment's asset and URL settings.
     *
     * @param  SiteTwigEnvironment  $twig     Isolated Twig environment scoped to site templates.
     * @param  ?ViteAssetManifest   $assets   Manifest of built frontend files, or null to fall back to
     *         the unhashed stylesheet a manifest-less build ships.
     * @param  string               $baseUrl  Public origin used to absolutise canonical paths; an empty
     *         string leaves them relative.
     *
     * @since  2.0.0
     */
    public function __construct(
        private SiteTwigEnvironment $twig,
        private ?ViteAssetManifest $assets = null,
        private string $baseUrl = '',
    ) {
    }

    /**
     * Renders one site template into the HTML body of a response.
     *
     * The caller's data is augmented before rendering: `site_assets` is always overwritten with the
     * resolved entry point, and a `canonical_url` given as a root-relative path is rewritten against the
     * configured base URL. A canonical URL that is already absolute is left as the caller supplied it.
     *
     * @param   string                $template  Template name without the `.twig` suffix, resolved
     *          against the site template namespace.
     * @param   array<string, mixed>  $data      View variables for the template, by Twig variable name.
     *
     * @return  string  The rendered HTML document.
     *
     * @throws  \RuntimeException  When the configured asset manifest exists but cannot be read, is not
     *          valid JSON, or declares no usable files for the site entry point.
     *
     * @since   2.0.0
     */
    public function render(string $template, array $data = []): string
    {
        $data['site_assets'] = ($this->assets ?? new ViteAssetManifest(''))->entry(
            'assets/site/main.ts',
            '/assets/site.css',
        )->toArray();
        $canonicalUrl = $data['canonical_url'] ?? null;
        if (is_string($canonicalUrl) && str_starts_with($canonicalUrl, '/') && $this->baseUrl !== '') {
            $data['canonical_url'] = rtrim($this->baseUrl, '/') . $canonicalUrl;
        }
        return $this->twig->render($template . '.twig', $data);
    }
}
