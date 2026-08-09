<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;

/**
 * Port answering the purely cryptographic half of the package trust question.
 *
 * It reports whether a detached signature verifies over a package digest under the key the signature
 * names, and nothing else. Whether that key is one this installation accepts is `PackageTrustPolicy`'s
 * decision, which is why this port returns a boolean instead of throwing. Because it holds the keys
 * itself, it suits an installation whose trusted keys are configured rather than administered;
 * `TrustKeySignatureVerifier` is the sibling port for keys that live in the trust store.
 *
 * @since  2.0.0
 */
interface PackageSignatureVerifier
{
    /**
     * Check a detached signature over a package digest.
     *
     * @param   PackageChecksum   $checksum   Digest of the package the signature is meant to cover.
     * @param   PackageSignature  $signature  Signature bytes together with the ID of the key that made them.
     *
     * @return  bool  True when the signature verifies; false when it does not or the key is unknown here.
     *
     * @since   2.0.0
     */
    public function verify(PackageChecksum $checksum, PackageSignature $signature): bool;
}
