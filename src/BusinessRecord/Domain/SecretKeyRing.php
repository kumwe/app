<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;

/**
 * One active key plus every retired key still needed to open what it sealed.
 *
 * A single key cannot be rotated: the moment it is replaced, every envelope written under it becomes
 * unreadable, so rotation and re-encryption have to happen atomically, which they cannot. A ring breaks
 * that deadlock. New writes always use the active key; a read resolves whichever key the envelope names,
 * which may be a key retired several rotations ago. That is what makes re-encryption an ordinary
 * background pass instead of an outage, and it is what keeps envelopes written under the old
 * `APP_SECRET`-derived key readable after a deployment moves to dedicated record key material.
 *
 * Resolution is by identifier and never by trial: a key the ring does not hold raises
 * `SecretKeyUnavailable` rather than being attempted, so an envelope from another installation fails as
 * a missing key instead of as a corrupted ciphertext. Retiring a key means removing it from the ring,
 * which is also how a revocation is expressed — from this side, a revoked key and a key that was never
 * configured are the same condition, and both fail closed.
 *
 * @since  2.0.0
 */
final readonly class SecretKeyRing
{
    /**
     * Retired keys by identifier, excluding the active one.
     *
     * @var    array<string, SecretKeyMaterial>
     * @since  2.0.0
     */
    private array $previous;

    /**
     * Assemble a ring from its active key and the retired keys still worth holding.
     *
     * A retired key sharing the active identifier is refused rather than silently dropped: two different
     * keys under one name would make an envelope's identifier ambiguous, which is exactly the property
     * the ring exists to provide.
     *
     * @param   SecretKeyMaterial        $active    Key every new envelope is sealed under.
     * @param   list<SecretKeyMaterial>  $previous  Retired keys, in any order, kept so envelopes written
     *          under them still open while re-encryption runs.
     *
     * @throws  InvalidArgumentException  When a retired key repeats the active identifier or another
     *          retired identifier, or more than 32 retired keys are supplied.
     *
     * @since   2.0.0
     */
    public function __construct(public SecretKeyMaterial $active, array $previous = [])
    {
        if (count($previous) > 32) {
            throw new InvalidArgumentException('A secret key ring holds at most 32 retired keys.');
        }
        $retired = [];
        foreach ($previous as $key) {
            if ($key->keyId === $this->active->keyId || array_key_exists($key->keyId, $retired)) {
                throw new InvalidArgumentException('A secret key ring cannot hold one identifier twice.');
            }
            $retired[$key->keyId] = $key;
        }
        ksort($retired, SORT_STRING);
        $this->previous = $retired;
    }

    /**
     * Resolve the key an envelope names, whether it is the active one or a retired one.
     *
     * The active identifier is compared in constant time, matching the comparison `SodiumSecretCipher`
     * has always made, so the common path leaks no timing signal about the identifier.
     *
     * @param   string  $keyId  Identifier read from the stored envelope.
     *
     * @return  SecretKeyMaterial  The key that identifier names.
     *
     * @throws  SecretKeyUnavailable  When the ring holds no key under that identifier, which covers a
     *          retired key that was dropped, a revoked key, and a key from another installation alike.
     *
     * @since   2.0.0
     */
    public function keyFor(string $keyId): SecretKeyMaterial
    {
        if (hash_equals($this->active->keyId, $keyId)) {
            return $this->active;
        }

        return $this->previous[$keyId] ?? throw new SecretKeyUnavailable($keyId);
    }

    /**
     * Name every key this ring can open an envelope with, active first.
     *
     * Operators use it to confirm that a retired key is still loaded before they start a rotation, and
     * that it is gone once the rotation has finished. Identifiers are names, not material.
     *
     * @return  non-empty-list<string>  The active identifier followed by the retired ones, in ascending
     *          string order.
     *
     * @since   2.0.0
     */
    public function keyIds(): array
    {
        return [$this->active->keyId, ...array_keys($this->previous)];
    }
}
