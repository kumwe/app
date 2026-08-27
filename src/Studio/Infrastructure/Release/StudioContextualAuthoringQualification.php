<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Release;

use InvalidArgumentException;

/**
 * App-owned qualification of one exact Studio deployment for the contextual PHP adapter.
 *
 * Filesystem records are evidence, not their own trust root. This value must therefore be created in
 * reviewed App wiring only when one coordinated Studio release, pin record, schema manifest, browser
 * runtime, and PHP adapter have passed qualification together. Until then the composition root passes
 * null and contextual mounting remains unavailable.
 *
 * @since  2.0.0
 */
final readonly class StudioContextualAuthoringQualification
{
    /**
     * Bind the adapter to immutable release and corpus evidence selected by App.
     *
     * @param  string  $release                 Exact coordinated semantic version.
     * @param  string  $releaseRecordSha256     Hex SHA-256 of `studio-release.json`.
     * @param  string  $pinRecordSha256         Hex SHA-256 of App's complete `PIN.json`.
     * @param  string  $schemaManifestSha256    Hex SHA-256 of the published schema manifest.
     * @param  string  $browserManifestSha256   Hex SHA-256 of the compiled Vite manifest.
     * @param  string  $browserEntrySha256      Hex SHA-256 of the contextual browser entry.
     *
     * @throws  InvalidArgumentException  When a coordinate cannot identify exact immutable evidence.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $release,
        public string $releaseRecordSha256,
        public string $pinRecordSha256,
        public string $schemaManifestSha256,
        public string $browserManifestSha256,
        public string $browserEntrySha256,
    ) {
        if (
            preg_match(
                '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/D',
                $release,
            ) !== 1
        ) {
            throw new InvalidArgumentException('Studio qualification requires an exact semantic release.');
        }
        foreach (
            [
                $releaseRecordSha256,
                $pinRecordSha256,
                $schemaManifestSha256,
                $browserManifestSha256,
                $browserEntrySha256,
            ] as $digest
        ) {
            if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('Studio qualification requires exact SHA-256 evidence.');
            }
        }
    }
}
