<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Asset;

use JsonException;
use RuntimeException;

/**
 * Resolves a Vite source entry point to the content-hashed files a template must actually link.
 *
 * Templates cannot know the hashed filenames a build produces, so `SiteRenderer` and
 * `AdministratorRenderer` ask this class for an `AssetEntry` instead of hard-coding paths. Two
 * situations are treated very differently: a checkout that has never been built has no manifest at all,
 * and there the caller's fallbacks are returned so pages still render; a manifest that exists but is
 * unreadable, malformed, or silent about the requested entry is a broken deployment, and this class
 * raises rather than quietly serving a page with no stylesheet.
 *
 * @since  2.0.0
 */
final readonly class ViteAssetManifest
{
    /**
     * Bind the reader to one build manifest and the URL prefix its files are served under.
     *
     * @param  string  $manifestPath  Path of the Vite `manifest.json`; absent means "never built".
     * @param  string  $publicPrefix  URL prefix prepended to every manifest path, trailing slash included.
     *
     * @since  2.0.0
     */
    public function __construct(private string $manifestPath, private string $publicPrefix = '/assets/build/')
    {
    }

    /**
     * Resolve one entry point to the stylesheets and modules its page must link.
     *
     * The entry is named the way Vite names it, by source path — `assets/site/main.ts`, for instance.
     * The fallbacks apply only when the manifest file is missing entirely; every other problem is a
     * misbuild and raises instead. A resolved entry carries exactly one module and however many
     * stylesheets Vite extracted for it, each already prefixed with the public build path and therefore
     * safe to emit as an `href` or `src`.
     *
     * @param   string       $source              Manifest key, which is the Vite source path of the entry.
     * @param   string       $fallbackStylesheet  Stylesheet URL to link when no build has run yet.
     * @param   string|null  $fallbackModule      Module URL for that same case; null when none is needed.
     *
     * @return  AssetEntry  Public URLs for the entry, resolved from the manifest or from the fallbacks.
     *
     * @throws  RuntimeException  When the manifest is unreadable or malformed, or the entry is missing.
     *
     * @since   2.0.0
     */
    public function entry(string $source, string $fallbackStylesheet, ?string $fallbackModule = null): AssetEntry
    {
        if (!is_file($this->manifestPath)) {
            return new AssetEntry(
                [$fallbackStylesheet],
                $fallbackModule === null ? [] : [$fallbackModule],
            );
        }

        $contents = file_get_contents($this->manifestPath);
        if (!is_string($contents)) {
            throw new RuntimeException('The frontend asset manifest cannot be read.');
        }

        try {
            $manifest = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The frontend asset manifest is invalid JSON.', 0, $exception);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('The frontend asset manifest must contain an object.');
        }

        $entry = $manifest[$source] ?? null;
        if (!is_array($entry) || array_is_list($entry)) {
            throw new RuntimeException(sprintf('The frontend asset entry %s is missing.', $source));
        }

        $file = $entry['file'] ?? null;
        if (!is_string($file) || $file === '') {
            throw new RuntimeException(sprintf('The frontend asset entry %s has no module.', $source));
        }
        $stylesheets = [];
        $css = $entry['css'] ?? [];
        if (!is_array($css) || !array_is_list($css)) {
            throw new RuntimeException(sprintf('The frontend asset entry %s has invalid stylesheets.', $source));
        }
        foreach ($css as $stylesheet) {
            if (!is_string($stylesheet) || $stylesheet === '') {
                throw new RuntimeException(sprintf('The frontend asset entry %s has an invalid stylesheet.', $source));
            }
            $stylesheets[] = $this->publicPrefix . ltrim($stylesheet, '/');
        }

        return new AssetEntry($stylesheets, [$this->publicPrefix . ltrim($file, '/')]);
    }
}
