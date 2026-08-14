<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

/**
 * Port that checks a revocation envelope's detached signature against the pinned feed key.
 *
 * It is a separate port from `TrustKeySignatureVerifier` because the two answer questions about
 * different trust domains. That one verifies a package against a key the installation administers and
 * can revoke; this one verifies a revocation list against a key the installation pins in configuration
 * and which nothing inside the application can withdraw — which is exactly the property that keeps a
 * compromised trust store from silencing the feed that would announce it.
 *
 * @since  2.0.0
 */
interface RevocationListVerifier
{
    /**
     * Report whether a signature covers the signed statement bytes under the pinned public key.
     *
     * @param   string  $publicKeyBase64  Standard base64 of the pinned 32-byte Ed25519 public key.
     * @param   string  $signedBytes      Exact statement text the signature must cover.
     * @param   string  $signatureBase64  Standard base64 of the detached Ed25519 signature.
     *
     * @return  bool  True only when the signature verifies; false for any malformed or mismatched input.
     *
     * @since   2.0.0
     */
    public function verify(string $publicKeyBase64, string $signedBytes, string $signatureBase64): bool;
}
