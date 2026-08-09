<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Trust;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Trust\PackageSignatureVerifier;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;

/**
 * Verifies package signatures with libsodium's Ed25519 against a fixed set of configured public keys.
 *
 * This is the `PackageSignatureVerifier` for installations whose trusted keys come from configuration
 * rather than from the administered trust store — `TrustKeySignatureVerifier` is the sibling that reads
 * the database. Keys are decoded and length-checked once, in the constructor, so a mistyped
 * configuration value fails where the verifier is assembled instead of halfway through an install; after
 * that a verification is a map lookup and a single `sodium_crypto_sign_verify_detached` call. An
 * unrecognised key identifier is reported as a failed verification rather than as an error, because
 * whether a key should have been known is `PackageTrustPolicy`'s decision and not this adapter's.
 *
 * @since  2.0.0
 */
final readonly class SodiumEd25519Verifier implements PackageSignatureVerifier
{
    /**
     * Raw 32-byte Ed25519 public keys, indexed by the signing key identifier they were configured under.
     *
     * @var    array<string, non-falsy-string>
     * @since  2.0.0
     */
    private array $publicKeys;

    /**
     * Decode and length-check every configured signing key.
     *
     * The parameter type is deliberately wide because the values arrive from configuration; this
     * constructor is the boundary that proves both the identifiers and the key material, so `verify()`
     * can hand the bytes straight to libsodium without re-checking them.
     *
     * @param   array<mixed>  $base64PublicKeys  Base64-encoded Ed25519 public keys. Keyed by signing key ID.
     *
     * @throws  InvalidArgumentException  When an entry is not a string under a string key, or does not
     *          decode to exactly 32 bytes.
     *
     * @since   2.0.0
     */
    public function __construct(array $base64PublicKeys)
    {
        $keys = [];

        foreach ($base64PublicKeys as $keyId => $base64PublicKey) {
            if (!is_string($keyId) || !is_string($base64PublicKey)) {
                throw new InvalidArgumentException('Signing keys must map string IDs to base64 public keys.');
            }

            $publicKey = base64_decode($base64PublicKey, true);

            if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException('Every Ed25519 public key must contain exactly 32 bytes.');
            }

            /** @var non-falsy-string $publicKey */
            $keys[$keyId] = $publicKey;
        }

        /** @var array<string, non-falsy-string> $keys */
        $this->publicKeys = $keys;
    }

    /**
     * Check a detached signature over a package digest under the key the signature names.
     *
     * The signed message is the checksum's string form, not the package bytes, so a signer never needs
     * the archive itself to produce a signature this accepts.
     *
     * @param   PackageChecksum   $checksum   Digest of the package the signature is meant to cover.
     * @param   PackageSignature  $signature  Signature bytes together with the ID of the key that made them.
     *
     * @return  bool  True when the signature verifies; false when it does not, and equally false when no
     *          key is configured under that identifier.
     *
     * @since   2.0.0
     */
    public function verify(PackageChecksum $checksum, PackageSignature $signature): bool
    {
        $publicKey = $this->publicKeys[$signature->keyId()] ?? null;

        return $publicKey !== null
            && sodium_crypto_sign_verify_detached($signature->bytes(), (string) $checksum, $publicKey);
    }
}
