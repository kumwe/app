<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Development;

use Kumwe\App\Extension\Domain\ExtensionManifest;
use Kumwe\App\Extension\Domain\PackageChecksum;

/**
 * Safe, code-free description of an inspected extension archive.
 *
 * @since  2.0.0
 */
final readonly class PackageInspection
{
    /**
     * Record archive identity, bounded size totals, paths, and the parsed manifest.
     *
     * @param  string             $archive        Canonical absolute archive path.
     * @param  PackageChecksum    $checksum       SHA-256 identity of the exact archive bytes.
     * @param  int                $expandedBytes  Sum of declared expanded regular-file bytes.
     * @param  list<string>       $paths          Archive paths in central-directory order.
     * @param  ExtensionManifest  $manifest       Strict parsed package manifest.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $archive,
        public PackageChecksum $checksum,
        public int $expandedBytes,
        public array $paths,
        public ExtensionManifest $manifest,
    ) {
    }

    /**
     * Export the stable package description used by console and conformance tooling.
     *
     * @return  array<string, mixed>  JSON-compatible package and manifest inventory.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'format' => 'kumwe-extension-inspection-v1',
            'archive' => $this->archive,
            'package_sha256' => (string) $this->checksum,
            'entry_count' => count($this->paths),
            'expanded_bytes' => $this->expandedBytes,
            'paths' => $this->paths,
            'manifest' => [
                'schema' => $this->manifest->schemaVersion(),
                'name' => $this->manifest->identifier()->value(),
                'type' => $this->manifest->type()->value,
                'version' => (string) $this->manifest->version(),
                'provider' => $this->manifest->serviceProvider(),
                'autoload' => ['psr-4' => $this->manifest->autoload()],
                'migrations' => $this->manifest->migrations(),
                'permissions' => $this->manifest->permissions(),
                'routes' => $this->manifest->routes(),
                'events' => $this->manifest->events(),
                'assets' => $this->manifest->assets(),
                'contributions' => $this->manifest->contributions()->toArray(),
            ],
        ];
    }
}
