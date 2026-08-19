<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Trust;

/**
 * Port that re-proves a deployed extension is still the artifact whose signature was accepted.
 *
 * A signature check at install time only covers the uploaded package; nothing in it stops the
 * unpacked files from being edited afterwards. `TrustStore` therefore calls this port every time it
 * re-establishes trust in an installed release, so the bytes actually about to be loaded are compared
 * against the digests the release record carries. Implementations refuse rather than report, which
 * keeps a tampered deployment from being downgraded into a warning that the runtime ignores.
 *
 * @since  2.0.0
 */
interface ExtensionArtifactVerifier
{
    /**
     * Assert that the deployment on disk still matches the digests recorded for the release.
     *
     * @param   array<string, mixed>  $release  Release record holding the digests captured at install time.
     *
     * @return  void
     *
     * @throws  UntrustedPackage  When the deployment is missing, unreadable, or no longer matches.
     *
     * @since   2.0.0
     */
    public function assertMatches(array $release): void;
}
