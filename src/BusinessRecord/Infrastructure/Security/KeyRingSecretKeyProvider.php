<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Security;

use Kumwe\App\BusinessRecord\Application\SecretKeyProvider;
use Kumwe\App\BusinessRecord\Domain\SecretKeyMaterial;
use Kumwe\App\BusinessRecord\Domain\SecretKeyRing;

/**
 * The production-capable default `SecretKeyProvider`: an in-process ring built from configuration.
 *
 * Key material arrives from the deployment — an environment variable, or a file the orchestrator mounts
 * and `ConfigurationFactory` reads — is derived into per-purpose keys by `ConfiguredSecretKeyRings`, and
 * is held in memory for the life of the process. That satisfies every clause of the adapter contract
 * without a network round trip: the active identifier cannot change mid-request, resolution is by
 * identifier, and an identifier the ring does not hold fails closed.
 *
 * It is the reference implementation an external adapter is measured against rather than a placeholder.
 * A KMS or HSM adapter replaces this one class and nothing else; what it must additionally solve —
 * caching, bounded latency, revocation — is what a ring in memory gets for free.
 *
 * @since  2.0.0
 */
final readonly class KeyRingSecretKeyProvider implements SecretKeyProvider
{
    /**
     * Bind the provider to the ring it answers from.
     *
     * @param  SecretKeyRing  $ring  Active key plus the retired keys this deployment still holds.
     *
     * @since  2.0.0
     */
    public function __construct(private SecretKeyRing $ring)
    {
    }

    /**
     * Name the ring's active key.
     *
     * @return  string  Identifier stamped into every envelope sealed from now on.
     *
     * @since   2.0.0
     */
    public function activeKeyId(): string
    {
        return $this->ring->active->keyId;
    }

    /**
     * Produce the ring's active key.
     *
     * @return  SecretKeyMaterial  The active key; a configured ring always has one, so this never fails.
     *
     * @since   2.0.0
     */
    public function activeKey(): SecretKeyMaterial
    {
        return $this->ring->active;
    }

    /**
     * Resolve the key an envelope names from the ring.
     *
     * @param   string  $keyId  Identifier read from the stored envelope.
     *
     * @return  SecretKeyMaterial  The key that identifier names.
     *
     * @throws  \Kumwe\App\BusinessRecord\Domain\SecretKeyUnavailable  When the ring holds no such key.
     *
     * @since   2.0.0
     */
    public function keyFor(string $keyId): SecretKeyMaterial
    {
        return $this->ring->keyFor($keyId);
    }

    /**
     * Name every key the ring can open an envelope with.
     *
     * @return  non-empty-list<string>  Active identifier first, then the retired ones in string order.
     *
     * @since   2.0.0
     */
    public function knownKeyIds(): array
    {
        return $this->ring->keyIds();
    }
}
