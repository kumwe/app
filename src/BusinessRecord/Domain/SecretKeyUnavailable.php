<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use RuntimeException;

/**
 * Signals that the key an envelope names is not held by this process.
 *
 * This is deliberately a different answer from a failed authentication. An envelope whose key the ring
 * does not hold has not been tampered with — it was sealed under a key that has been retired without
 * being kept, revoked by an external provider, or that belongs to another installation entirely — and
 * telling the two apart is what lets an operator distinguish "restore the key" from "this ciphertext is
 * not what it claims". It extends `RuntimeException`, which is the type `SecretCipher::decrypt()` has
 * always documented, so every existing caller keeps catching it unchanged.
 *
 * The message names the identifier only, never the key material, the plaintext, or the identifiers of
 * the keys that *are* held: an unavailable-key error reaching a log must not become a map of the ring.
 *
 * @since  2.0.0
 */
final class SecretKeyUnavailable extends RuntimeException
{
    /**
     * Report that one named key is not available for use.
     *
     * @param  string  $keyId  Identifier the envelope named. It is a key *name*, never key bytes, and is
     *         included so an operator can tell which key to restore; a malformed identifier is reported
     *         without being echoed.
     *
     * @since  2.0.0
     */
    public function __construct(string $keyId)
    {
        parent::__construct(sprintf(
            'The business-record secret encryption key "%s" is unavailable.',
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,126}$/D', $keyId) === 1 ? $keyId : 'unnamed',
        ));
    }
}
