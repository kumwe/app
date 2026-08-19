<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use InvalidArgumentException;

/**
 * A sealed secret field value together with everything needed to open it again.
 *
 * `core.secret` fields never hold plaintext inside a business record; `SodiumSecretCipher` returns
 * one of these instead, and the record carries it until something with the key asks for the value
 * back. Naming the key and the construction alongside the ciphertext is what makes key rotation
 * survivable: a decrypt against the wrong key is refused by identifier rather than mistaken for
 * corrupt data. The constructor is the single gate, so an envelope rebuilt from a stored row is
 * validated exactly as one produced by the cipher.
 *
 * @since  2.0.0
 */
final readonly class EncryptedEnvelope
{
    /**
     * The only AEAD construction envelopes may be sealed with, in its portable spelling.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ALGORITHM = 'xchacha20poly1305-ietf';

    /**
     * Assemble an envelope and prove each part is well formed before it can be stored or opened.
     *
     * @param   string  $ciphertext  Raw sealed bytes including the authentication tag; non-empty, at
     *          most one mebibyte.
     * @param   string  $nonce       Raw nonce bytes, exactly the XChaCha20-Poly1305 nonce length.
     * @param   string  $keyId       Identifier of the key the ciphertext was sealed with, so a rotated
     *          key is recognised instead of silently mis-applied.
     * @param   string  $algorithm   Construction the ciphertext was produced with; anything other than
     *          `self::ALGORITHM` is refused, which is what stops a downgraded stored row from loading.
     *
     * @throws  InvalidArgumentException  When the ciphertext is empty or oversized, the nonce is the
     *          wrong length, the key identifier is malformed, or the algorithm is unsupported.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public string $keyId,
        public string $algorithm = self::ALGORITHM,
    ) {
        if ($ciphertext === '' || strlen($ciphertext) > 1_048_576) {
            throw new InvalidArgumentException('An encrypted envelope ciphertext is empty or unbounded.');
        }
        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new InvalidArgumentException('An encrypted envelope nonce has an invalid size.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,126}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('An encrypted envelope key identifier is invalid.');
        }
        if ($algorithm !== self::ALGORITHM) {
            throw new InvalidArgumentException('An encrypted envelope algorithm is unsupported.');
        }
    }

    /**
     * Export the envelope in the text-safe shape `RecordValueGuard` canonicalises it into.
     *
     * This is the form that reaches a revision snapshot and its checksum, which is why the two byte
     * strings are base64-encoded: raw AEAD output is not valid UTF-8 and could not be JSON-encoded.
     *
     * @return  array{ciphertext: string, nonce: string, key_id: string, algorithm: string}  Byte strings
     *          base64-encoded, key identifier and algorithm verbatim.
     *
     * @since   2.0.0
     */
    public function toStorage(): array
    {
        return [
            'ciphertext' => base64_encode($this->ciphertext),
            'nonce' => base64_encode($this->nonce),
            'key_id' => $this->keyId,
            'algorithm' => $this->algorithm,
        ];
    }

    /**
     * Rebuild an envelope from its canonical row, decoding the two base64 byte strings.
     *
     * Decoding is strict, so padding or alphabet damage in the row is reported here rather than
     * surfacing later as a failed decryption.
     *
     * @param   array{ciphertext: string, nonce: string, key_id: string, algorithm: string}  $storage  Row as
     *          written by `toStorage()`.
     *
     * @return  self  An envelope that has passed the same checks as a freshly sealed one.
     *
     * @throws  InvalidArgumentException  When either byte string is not valid base64, or the decoded
     *          envelope fails construction.
     *
     * @since   2.0.0
     */
    public static function fromStorage(array $storage): self
    {
        $ciphertext = base64_decode($storage['ciphertext'], true);
        $nonce = base64_decode($storage['nonce'], true);
        if (!is_string($ciphertext) || !is_string($nonce)) {
            throw new InvalidArgumentException('An encrypted envelope contains invalid base64 data.');
        }

        return new self($ciphertext, $nonce, $storage['key_id'], $storage['algorithm']);
    }
}
