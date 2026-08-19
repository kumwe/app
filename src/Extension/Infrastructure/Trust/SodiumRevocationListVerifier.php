<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Infrastructure\Trust;

use Kumwe\App\Extension\Application\Trust\RevocationListVerifier;
use SodiumException;

/**
 * Verifies revocation envelopes with libsodium's Ed25519 against the key pinned in configuration.
 *
 * Every malformed input is reported as a failed verification rather than raised, including a key that
 * does not decode to 32 bytes and a signature that does not decode to 64. A revocation list that fails
 * for any reason is refused and recorded as an integrity failure, so collapsing the reasons here costs
 * nothing an operator needs and keeps a libsodium error from reaching the automation worker as an
 * unclassified exception.
 *
 * @since  2.0.0
 */
final readonly class SodiumRevocationListVerifier implements RevocationListVerifier
{
    /**
     * Check a detached signature over the statement bytes under the pinned public key.
     *
     * @param   string  $publicKeyBase64  Standard base64 of the pinned 32-byte Ed25519 public key.
     * @param   string  $signedBytes      Exact statement text the signature must cover.
     * @param   string  $signatureBase64  Standard base64 of the detached Ed25519 signature.
     *
     * @return  bool  True only when the signature verifies.
     *
     * @since   2.0.0
     */
    public function verify(string $publicKeyBase64, string $signedBytes, string $signatureBase64): bool
    {
        $publicKey = base64_decode($publicKeyBase64, true);
        $signature = base64_decode($signatureBase64, true);
        if (
            !is_string($publicKey)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $signedBytes, $publicKey);
        } catch (SodiumException) {
            return false;
        }
    }
}
