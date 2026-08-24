<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\App\Extension\Runtime\RuntimeArtifactDigester;
use RuntimeException;

/**
 * Immutable deployment coordinate for the built-in public templates and exact Vite site entry.
 *
 * @since  2.0.0
 */
final readonly class StudioBuiltInThemeRelease
{
    /**
     * Capture a digest already derived from trusted deployment bytes.
     *
     * @param  string  $revision  Lowercase SHA-256 digest of release, templates, and site assets.
     *
     * @since  2.0.0
     */
    public function __construct(public string $revision)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new RuntimeException('The built-in public theme revision is invalid.');
        }
    }

    /**
     * Digest the exact built-in theme bytes this process will publish.
     *
     * The site entry follows its closed file, CSS, asset, static-import, and dynamic-import graph.
     * Administrator and portal assets do not invalidate a public-theme lock. A manifest-less deployment
     * binds the fallback stylesheet instead. The application release remains in the preimage to cover
     * trusted renderer code outside the template and asset trees.
     *
     * @param   string                   $root      Absolute App repository/deployment root.
     * @param   string                   $release   Immutable configured application release.
     * @param   RuntimeArtifactDigester  $digester  Safe deterministic directory digester.
     *
     * @return  self  Exact built-in public-theme release coordinate.
     *
     * @throws  RuntimeException  When an advertised asset is absent, unsafe, or unreadable.
     * @throws  \JsonException  When the manifest or digest preimage cannot be decoded or encoded.
     *
     * @since   2.0.0
     */
    public static function fromDeployment(
        string $root,
        string $release,
        RuntimeArtifactDigester $digester,
    ): self {
        $coordinates = [
            'contract' => 'kumwe.app/built-in-site-theme-v1',
            'release' => $release,
            'templates' => $digester->digest($root . '/templates/site'),
            'assets' => self::assetDigests($root),
        ];

        return new self(hash('sha256', (string) json_encode(
            $coordinates,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        )));
    }

    /**
     * Hash the runtime files reachable from the exact site Vite entry, or its manifest-less fallback.
     *
     * @param   string  $root  Absolute App repository/deployment root.
     *
     * @return  array<string, string>  Path-sorted runtime file digests.
     *
     * @since   2.0.0
     */
    private static function assetDigests(string $root): array
    {
        $manifestPath = $root . '/public/assets/build/.vite/manifest.json';
        if (!is_file($manifestPath)) {
            return ['public/assets/site.css' => self::fileDigest($root . '/public/assets/site.css')];
        }
        $encoded = file_get_contents($manifestPath);
        $manifest = is_string($encoded) ? json_decode($encoded, true, 64, JSON_THROW_ON_ERROR) : null;
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('The Vite manifest is invalid.');
        }
        $pending = ['assets/site/main.ts'];
        $visited = [];
        $files = [];
        while ($pending !== []) {
            $key = array_pop($pending);
            if (!is_string($key) || isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            $entry = $manifest[$key] ?? null;
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException('The Vite site entry is unavailable.');
            }
            foreach (['file'] as $member) {
                $file = $entry[$member] ?? null;
                if (!is_string($file)) {
                    throw new RuntimeException('A Vite site asset coordinate is invalid.');
                }
                $files['public/assets/build/' . $file] = self::assetDigest($root, $file);
            }
            foreach (['css', 'assets'] as $member) {
                $values = $entry[$member] ?? [];
                if (!is_array($values) || !array_is_list($values)) {
                    throw new RuntimeException('A Vite site asset list is invalid.');
                }
                foreach ($values as $file) {
                    if (!is_string($file)) {
                        throw new RuntimeException('A Vite site asset coordinate is invalid.');
                    }
                    $files['public/assets/build/' . $file] = self::assetDigest($root, $file);
                }
            }
            foreach (['imports', 'dynamicImports'] as $member) {
                $values = $entry[$member] ?? [];
                if (!is_array($values) || !array_is_list($values)) {
                    throw new RuntimeException('A Vite site import list is invalid.');
                }
                foreach ($values as $import) {
                    if (!is_string($import)) {
                        throw new RuntimeException('A Vite site import coordinate is invalid.');
                    }
                    $pending[] = $import;
                }
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Resolve and hash one closed manifest-relative build asset.
     *
     * @param   string  $root      Absolute App root.
     * @param   string  $relative  Manifest-relative build path.
     *
     * @return  string  Lowercase SHA-256 file digest.
     *
     * @since   2.0.0
     */
    private static function assetDigest(string $root, string $relative): string
    {
        if (
            preg_match('#^(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$#D', $relative) !== 1
            || str_contains($relative, '..')
        ) {
            throw new RuntimeException('A Vite site asset path is unsafe.');
        }

        return self::fileDigest($root . '/public/assets/build/' . $relative);
    }

    /**
     * Hash one required regular non-symbolic file.
     *
     * @param   string  $path  Absolute file path.
     *
     * @return  string  Lowercase SHA-256 digest.
     *
     * @since   2.0.0
     */
    private static function fileDigest(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('A built-in public theme asset is missing or unsafe.');
        }
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new RuntimeException('A built-in public theme asset could not be digested.');
        }

        return $digest;
    }
}
