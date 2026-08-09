<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;

/**
 * Detached Ed25519 signature offered with an extension package, paired with the key that made it.
 *
 * Both halves are needed to answer the trust question, and each half is answered by a different
 * collaborator: `PackageTrustPolicy` and `TrustStore` decide whether the named key is one this
 * installation accepts, while a `PackageSignatureVerifier` checks the bytes against the package
 * digest. Decoding and length validation happen once, here, so no malformed signature ever reaches
 * the cryptographic call.
 *
 * @since  2.0.0
 */
final readonly class PackageSignature
{
    /**
     * Bind decoded signature bytes to the key that produced them.
     *
     * @param  string            $keyId  Signing key this signature must be verified under.
     * @param  non-falsy-string  $bytes  Raw signature, already decoded and length checked.
     *
     * @since  2.0.0
     */
    private function __construct(
        private string $keyId,
        private string $bytes,
    ) {
    }

    /**
     * Build a signature from the base64 form a package or release feed carries.
     *
     * Decoding is strict, so padding or alphabet errors fail here rather than producing bytes that
     * silently fail verification later.
     *
     * @param   string  $keyId            Signing key ID; lowercase, 3 to 127 characters.
     * @param   string  $base64Signature  Detached signature, base64 encoded.
     *
     * @return  self  The decoded signature bound to its key ID.
     *
     * @throws  InvalidArgumentException  When the key ID is not a stable identifier or the signature is not 64 bytes.
     *
     * @since   2.0.0
     */
    public static function ed25519(string $keyId, string $base64Signature): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('A signature key ID must be a stable lowercase identifier.');
        }

        $bytes = base64_decode($base64Signature, true);

        if (!is_string($bytes) || strlen($bytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidArgumentException('An Ed25519 signature must contain exactly 64 bytes.');
        }

        /** @var non-falsy-string $bytes */
        return new self($keyId, $bytes);
    }

    /**
     * Name the key a verifier must resolve a public key for before checking these bytes.
     *
     * @return  string  Lowercase key ID as the signer wrote it, matched against the trusted key set.
     *
     * @since   2.0.0
     */
    public function keyId(): string
    {
        return $this->keyId;
    }

    /**
     * Name the signature scheme, for storage columns and operator-facing output.
     *
     * @return  string  Always `ed25519`; this type models no other scheme.
     *
     * @since   2.0.0
     */
    public function algorithm(): string
    {
        return 'ed25519';
    }

    /**
     * Hand the raw signature to the verification primitive.
     *
     * @return  non-falsy-string  Exactly 64 decoded bytes, in the form Ed25519 verification expects.
     *
     * @since   2.0.0
     */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * Re-encode the signature for transport or storage in a text field.
     *
     * @return  string  Base64 of the same bytes `ed25519()` decoded.
     *
     * @since   2.0.0
     */
    public function asBase64(): string
    {
        return base64_encode($this->bytes);
    }
}
