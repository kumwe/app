<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Asset;

use JsonException;
use RuntimeException;

final readonly class ViteAssetManifest
{
    public function __construct(private string $manifestPath, private string $publicPrefix = '/assets/build/')
    {
    }

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
