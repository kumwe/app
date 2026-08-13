<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Domain\SecretKeyMaterial;
use Kumwe\CMS\BusinessRecord\Domain\SecretKeyUnavailable;

/**
 * Port through which the cipher acquires key material, separate from the cipher itself.
 *
 * `SecretCipher` says how bytes are sealed; this says where the key comes from. Splitting them is what
 * lets an installation keep the shipped file-and-environment provider, or put a managed KMS or an HSM
 * behind the same two questions — which key should new writes use, and can you produce the key this
 * envelope names — without the record runtime knowing which answer it is talking to.
 *
 * **Adapter contract.** An implementation owes the following, and `docs/business-security.md` states it
 * for operators in the same terms:
 *
 * - *Identifier namespace.* `activeKeyId()` returns a stable, versioned name matching
 *   `^[A-Za-z0-9][A-Za-z0-9._:-]{0,126}$`. It is written into every envelope and is the only thing the
 *   database records about the key, so it must never be reused for different key material — a new key
 *   is a new identifier, always.
 * - *Stability within a process.* `activeKeyId()` and `activeKey()` must agree and must not change
 *   during one request or one job. A provider that rotates underneath a running batch would leave
 *   envelopes stamped with an identifier the bytes do not match.
 * - *Fail closed.* `keyFor()` raises `SecretKeyUnavailable` for an identifier it cannot produce,
 *   including a revoked one, and never substitutes another key. Returning the wrong key would turn a
 *   recoverable "restore the key" into a silent authentication failure.
 * - *Disclosure.* No implementation may log, print, or attach key material to an exception, a metric,
 *   or a trace. `SecretKeyMaterial` redacts itself; an adapter must not undo that by logging the bytes
 *   it received before wrapping them.
 * - *Latency and caching.* Every record write and every re-encryption calls `activeKey()`, so a remote
 *   provider must cache within the process and must bound its network wait; a provider that blocks
 *   makes record writes block. A cache entry is dropped on revocation signalling, which for this port
 *   means the next `keyFor()` raising `SecretKeyUnavailable`.
 * - *Audit.* Key use is not audited here — the record trail already records the mutation, and the
 *   rotation pass records what it re-encrypted. An external provider is expected to keep its own access
 *   log; that log, not this port, is where "who asked for which key when" is answered.
 *
 * @since  2.0.0
 */
interface SecretKeyProvider
{
    /**
     * Name the key every new envelope must be sealed under.
     *
     * @return  string  Identifier of the active key, in the namespace described above.
     *
     * @since   2.0.0
     */
    public function activeKeyId(): string;

    /**
     * Produce the key every new envelope must be sealed with.
     *
     * @return  SecretKeyMaterial  Active key and its identifier, which must equal `activeKeyId()`.
     *
     * @throws  SecretKeyUnavailable  When the provider cannot produce its own active key, which is a
     *          deployment fault rather than a data fault and must stop writes rather than degrade them.
     *
     * @since   2.0.0
     */
    public function activeKey(): SecretKeyMaterial;

    /**
     * Produce the key one stored envelope names.
     *
     * @param   string  $keyId  Identifier read from the envelope, which may name a retired key.
     *
     * @return  SecretKeyMaterial  The key that identifier names.
     *
     * @throws  SecretKeyUnavailable  When the identifier names a key this provider does not hold, has
     *          retired, or has had revoked.
     *
     * @since   2.0.0
     */
    public function keyFor(string $keyId): SecretKeyMaterial;

    /**
     * Name every key this provider can currently open an envelope with.
     *
     * Operators read this before and after a rotation to confirm that a retired key is still loaded, and
     * that it has been dropped once nothing references it. A provider that cannot enumerate its keys —
     * some KMS deployments deliberately cannot — returns just the active identifier, which is honest:
     * it says only that the active key is present, not that no others are.
     *
     * @return  non-empty-list<string>  Identifiers, active first; names only, never material.
     *
     * @since   2.0.0
     */
    public function knownKeyIds(): array;
}
