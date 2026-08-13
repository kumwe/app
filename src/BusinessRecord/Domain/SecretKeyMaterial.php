<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * One AEAD key together with the identifier envelopes record it under.
 *
 * A key on its own cannot be rotated, because nothing sealed with it says so; pairing the bytes with a
 * versioned name is what lets `SecretKeyRing` hand back the right key for an envelope written years ago
 * instead of trying keys until one happens to authenticate. The bytes stay private and are reachable only
 * through `material()`, the constructor parameter is marked sensitive so a stack trace redacts it, and
 * `__debugInfo()` replaces it, so a `var_dump`, a serialized exception, or a debug page cannot spill a key
 * that the rest of the system is careful never to log.
 *
 * @since  2.0.0
 */
final readonly class SecretKeyMaterial
{
    /**
     * Bind an identifier to its key and prove both halves are usable before anything is sealed.
     *
     * @param   string  $keyId     Name of the key, stamped into every envelope it seals; an alphanumeric
     *          first character followed by up to 126 more of `A-Za-z0-9._:-`.
     * @param   string  $material  Raw XChaCha20-Poly1305 key bytes of exactly the algorithm's key length —
     *          derived material, never a passphrase or its hexadecimal spelling.
     *
     * @throws  InvalidArgumentException  When the identifier does not match the accepted shape, or the key
     *          is not exactly the algorithm's key size. Neither message quotes the key.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $keyId,
        #[SensitiveParameter] private string $material,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,126}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('A secret encryption key identifier is invalid.');
        }
        if (strlen($material) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('A secret encryption key has an invalid size.');
        }
    }

    /**
     * Hand the raw key bytes to the one collaborator entitled to them.
     *
     * Only a cipher calls this. Everything else works with the identifier, which is safe to log.
     *
     * @return  string  The raw key bytes, exactly as supplied.
     *
     * @since   2.0.0
     */
    public function material(): string
    {
        return $this->material;
    }

    /**
     * Present the key to a debugger with its bytes replaced.
     *
     * `var_dump()` on an object graph is one of the ways key material escapes into a log or a bug report,
     * and this is the hook that stops it: the identifier is disclosed, the key never is.
     *
     * @return  array{keyId: string, material: string}  The identifier, and a fixed redaction marker.
     *
     * @since   2.0.0
     */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId, 'material' => '[redacted]'];
    }
}
