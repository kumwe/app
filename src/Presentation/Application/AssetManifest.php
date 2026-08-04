<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use InvalidArgumentException;

final readonly class AssetManifest
{
    /** @var array<string, Asset> */
    private array $assets;

    public function __construct(Asset ...$assets)
    {
        $indexed = [];

        foreach ($assets as $asset) {
            if (isset($indexed[$asset->name()])) {
                throw new InvalidArgumentException(sprintf('Asset %s is declared twice.', $asset->name()));
            }

            $indexed[$asset->name()] = $asset;
        }

        foreach ($indexed as $asset) {
            foreach ($asset->dependencies() as $dependency) {
                if (!isset($indexed[$dependency])) {
                    throw new InvalidArgumentException(sprintf(
                        'Asset %s depends on unknown asset %s.',
                        $asset->name(),
                        $dependency,
                    ));
                }
            }
        }

        $this->assets = $indexed;
    }

    public function url(string $name, string $basePath = '/assets'): string
    {
        if (
            !str_starts_with($basePath, '/')
            || str_contains($basePath, '..')
            || str_contains($basePath, "\0")
            || str_contains($basePath, '\\')
            || str_contains($basePath, '?')
            || str_contains($basePath, '#')
            || preg_match('#^//#D', $basePath) === 1
        ) {
            throw new InvalidArgumentException('The asset base path must be an absolute local URL path.');
        }

        return rtrim($basePath, '/') . '/' . $this->asset($name)->path();
    }

    /**
     * @param list<string> $names
     *
     * @return list<Asset>
     */
    public function ordered(array $names): array
    {
        $ordered = [];
        $permanent = [];
        $temporary = [];

        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        foreach ($names as $name) {
            $this->visit($name, $ordered, $permanent, $temporary);
        }

        return $ordered;
    }

    public function asset(string $name): Asset
    {
        if (!isset($this->assets[$name])) {
            throw new InvalidArgumentException(sprintf('Asset %s is not declared.', $name));
        }

        return $this->assets[$name];
    }

    /**
     * @param list<Asset>         $ordered
     * @param array<string, true> $permanent
     * @param array<string, true> $temporary
     */
    private function visit(string $name, array &$ordered, array &$permanent, array &$temporary): void
    {
        if (isset($permanent[$name])) {
            return;
        }

        if (isset($temporary[$name])) {
            throw new InvalidArgumentException(sprintf('Asset dependency cycle detected at %s.', $name));
        }

        $asset = $this->asset($name);
        $temporary[$name] = true;
        $dependencies = $asset->dependencies();
        sort($dependencies, SORT_STRING);

        foreach ($dependencies as $dependency) {
            $this->visit($dependency, $ordered, $permanent, $temporary);
        }

        unset($temporary[$name]);
        $permanent[$name] = true;
        $ordered[] = $asset;
    }
}
