<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Infrastructure\Trust;

use Kumwe\App\Extension\Application\Trust\TrustKeySignatureVerifier;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignature;

/**
 * Verifies package signatures with libsodium's Ed25519 against a key handed over by the trust store.
 *
 * This is the cryptographic half of `TrustKeySignatureVerifier`: `TrustStore` has already decided that
 * the key the signature names is enabled, unrevoked, unexpired and admitted for the extension being
 * installed, and passes its stored base64 public key here. The adapter holds no key material of its own,
 * which is what lets an operator add, rotate or revoke a signing key without the verifier being rebuilt;
 * `SodiumEd25519Verifier` is the sibling for installations whose keys come from configuration instead.
 * Key and signature are decoded and length-checked before libsodium is called, because
 * `sodium_crypto_sign_verify_detached()` raises on a wrongly sized argument and this port promises a
 * plain false for anything malformed.
 *
 * @since  2.0.0
 */
final readonly class SodiumTrustKeySignatureVerifier implements TrustKeySignatureVerifier
{
    /**
     * Check a detached signature over a package digest under the supplied public key.
     *
     * The signed message is the checksum's hexadecimal string form rather than the package bytes, so
     * verification never needs the archive in memory.
     *
     * @param   string            $publicKeyBase64  Base64 Ed25519 public key as stored on the trust key
     *          record the signature named.
     * @param   PackageChecksum   $checksum         Digest of the package the signature must cover.
     * @param   PackageSignature  $signature        Detached signature presented with the package.
     *
     * @return  bool  True only when the signature verifies; false for a key or signature of the wrong
     *          length, for base64 that does not decode, and for a plain mismatch.
     *
     * @since   2.0.0
     */
    public function verify(
        string $publicKeyBase64,
        PackageChecksum $checksum,
        PackageSignature $signature,
    ): bool {
        $publicKey = base64_decode($publicKeyBase64, true);
        $signatureBytes = base64_decode($signature->asBase64(), true);
        if (
            !is_string($publicKey)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($signatureBytes)
            || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($signatureBytes, (string) $checksum, $publicKey);
    }
}
