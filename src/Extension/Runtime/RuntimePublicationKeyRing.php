<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use InvalidArgumentException;
use RuntimeException;

/**
 * Symmetric key ring that signs runtime publications and verifies the ones already in circulation.
 *
 * Runtime trust rests on an HMAC over each publication document and over the local markers derived from
 * it, so the same secret has to be present wherever a publication is written and wherever it is read
 * back. Keeping retired keys beside the active one is what makes rotation survivable: a replica already
 * running the new key still verifies the publications and markers signed under the old one, while
 * everything it signs itself carries the active identifier. Keys are validated once, at construction,
 * and the ring is immutable afterwards, so nothing downstream can widen what verifies. This is not the
 * package-signing trust store — vendor signatures are Ed25519 and live in `TrustStore`; these secrets
 * only bind a publication to the installation that produced it.
 *
 * @since  2.0.0
 */
final readonly class RuntimePublicationKeyRing
{
    /**
     * Every accepted secret, active and retired alike, keyed by the identifier documents name it under.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $keys;

    /**
     * Assemble the ring, refusing any key that is badly identified or too short to be a secret.
     *
     * The active key is merged over the previous ones and the resulting count is checked afterwards,
     * which is how a ring that also lists its active key among the previous ones is rejected instead of
     * silently collapsing two entries into one.
     *
     * @param   string                 $activeKeyId   Identifier recorded on everything this ring signs,
     *          so a later verifier knows which secret to select.
     * @param   string                 $activeKey     Secret new publications, verification markers and
     *          readiness markers are signed with; at least 32 bytes.
     * @param   array<string, string>  $previousKeys  Retired secrets that must still verify, keyed by the
     *          identifier the documents signed under them carry.
     *
     * @throws  InvalidArgumentException  When a key identifier is not 3 to 127 characters of lowercase
     *          letters, digits, dot, underscore, colon or hyphen starting with a letter or digit, when a
     *          secret is shorter than 32 bytes, or when the active key repeats a previous identifier.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $activeKeyId,
        #[\SensitiveParameter] string $activeKey,
        #[\SensitiveParameter] array $previousKeys = [],
    ) {
        $keys = $previousKeys;
        $keys[$activeKeyId] = $activeKey;
        foreach ($keys as $keyId => $key) {
            if (
                preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $keyId) !== 1
                || !is_string($key)
                || strlen($key) < 32
            ) {
                throw new InvalidArgumentException('Runtime publication signing keys are invalid.');
            }
        }
        if (count($keys) !== count($previousKeys) + 1) {
            throw new InvalidArgumentException('The active runtime signing key cannot also be a previous key.');
        }

        $this->keys = $keys;
    }

    /**
     * Authenticate a payload with the ring's active key.
     *
     * Only the active key ever signs; retired keys are kept for verification alone, so a rotation
     * cannot be undone by an attacker who later recovers a superseded secret. Callers are expected to
     * store `activeKeyId` beside the digest, because that is what lets the verifier pick the key again.
     *
     * @param   string  $payload  Bytes to authenticate, such as `generation:publicationChecksum`.
     *
     * @return  string  Lowercase SHA-256 HMAC hex digest of the payload under the active key.
     *
     * @since   2.0.0
     */
    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->keys[$this->activeKeyId]);
    }

    /**
     * Derive a stable fingerprint of the whole ring that is safe to embed in a cache key.
     *
     * `ExtensionRuntimeMapCompiler` mixes this into the APCu key it memoises a verified local
     * publication under, so adding, retiring or rotating a key misses the cache rather than serving a
     * verification the current ring would no longer make. Each secret is hashed before it is combined
     * and the identifiers are sorted first, so the result is order independent and exposes no key
     * material to anything that can read the cache key.
     *
     * @return  string  Lowercase SHA-256 hex digest over every key identifier and the digest of its secret.
     *
     * @since   2.0.0
     */
    public function cacheIdentity(): string
    {
        $identity = [];
        foreach ($this->keys as $keyId => $key) {
            $identity[$keyId] = hash('sha256', $key);
        }
        ksort($identity, SORT_STRING);

        return hash('sha256', RuntimeCanonicalJson::encode($identity));
    }

    /**
     * Require that a signature over a payload was produced by one of the ring's keys.
     *
     * An identifier the ring does not hold is refused with the same failure as a wrong digest, and
     * digests that are compared at all are compared in constant time, so a caller feeding candidate
     * signatures learns neither which keys exist nor where its guess first diverged.
     *
     * @param   string  $keyId      Identifier the document names as having signed it.
     * @param   string  $payload    Bytes the signature is expected to authenticate.
     * @param   string  $signature  HMAC digest carried alongside the payload.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the ring holds no key under that identifier, or the signature does
     *          not match the payload under it.
     *
     * @since   2.0.0
     */
    public function assertSignature(string $keyId, string $payload, string $signature): void
    {
        $key = $this->keys[$keyId] ?? null;
        if (!is_string($key) || !hash_equals(hash_hmac('sha256', $payload, $key), $signature)) {
            throw new RuntimeException('The runtime publication signature is invalid or uses an unavailable key.');
        }
    }
}
