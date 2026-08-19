<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Asset;

/**
 * Resolved set of built frontend files a template must link for one Vite entry point.
 *
 * The presentation layer never references source paths. It asks `ViteAssetManifest` for an entry and
 * renders whatever this value object carries, so a rebuild that changes content hashes needs no
 * template change. Both lists are already prefixed with the public build path and are safe to emit as
 * `href` and `src` attributes.
 *
 * @since  2.0.0
 */
final readonly class AssetEntry
{
    /**
     * Capture the files an entry point resolves to.
     *
     * @param  list<string>  $stylesheets  Public URLs of stylesheets to link, in load order.
     * @param  list<string>  $modules      Public URLs of ES modules to load, in execution order.
     *
     * @since  2.0.0
     */
    public function __construct(public array $stylesheets, public array $modules)
    {
    }

    /**
     * Export the entry in the shape the Twig asset helpers iterate over.
     *
     * @return  array{stylesheets: list<string>, modules: list<string>}  The two link lists, keyed by kind.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['stylesheets' => $this->stylesheets, 'modules' => $this->modules];
    }
}
