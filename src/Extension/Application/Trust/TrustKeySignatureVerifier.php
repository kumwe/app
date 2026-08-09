<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;

/**
 * Port that checks a package signature against a public key drawn from the extension trust store.
 *
 * `TrustStore` owns the policy half of the question — it resolves the key named by the signature, and
 * only hands work here once that key is enabled, unrevoked, unexpired and admitted by its own vendor and
 * extension constraints. This port answers the cryptographic half, so no signing algorithm knowledge
 * leaks into the application layer. An implementation must report false rather than raise for a
 * malformed key, a malformed signature or a plain mismatch, so that every rejection reaches the caller
 * as one `UntrustedPackage` instead of a driver-shaped error.
 *
 * @since  2.0.0
 */
interface TrustKeySignatureVerifier
{
    /**
     * Report whether a signature covers a package checksum under the supplied public key.
     *
     * @param   string            $publicKeyBase64  Base64 public key as stored on the trust key record.
     * @param   PackageChecksum   $checksum         Digest of the package bytes the signature must cover.
     * @param   PackageSignature  $signature        Detached signature presented alongside the package.
     *
     * @return  bool  True only when the signature verifies; false for any malformed or mismatched input.
     *
     * @since   2.0.0
     */
    public function verify(
        string $publicKeyBase64,
        PackageChecksum $checksum,
        PackageSignature $signature,
    ): bool;
}
