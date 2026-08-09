<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use DateTimeImmutable;

/**
 * Persistence port for extension signing keys and the installed-release rows their trust governs.
 *
 * `TrustStore` keeps the policy — who may add a key, what a rotation must preserve, when a release is
 * quarantined — and delegates every read and write here, which is what lets the application layer stay
 * free of SQL while still being the only writer of trust state. Two obligations reach past plain
 * storage. `synchronizedLifecycle()` must serialize extension lifecycle work across the whole
 * installation, not merely across one process. `lockGeneration()` must hold the trust generation row
 * for the surrounding transaction, so a mutation and a concurrent runtime trust check cannot interleave
 * and observe half a change.
 *
 * @since  2.0.0
 */
interface TrustStoreRepository
{
    /**
     * Run an operation while holding the installation-wide extension lifecycle lock.
     *
     * The lock is exclusive rather than queued: an implementation refuses outright when another
     * lifecycle operation already holds it instead of waiting for that one to finish.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Lifecycle work to run while the lock is held.
     *
     * @return  T  Whatever the operation returned, passed back unchanged.
     *
     * @since   2.0.0
     */
    public function synchronizedLifecycle(callable $operation): mixed;

    /**
     * Report whether the trust schema is migrated far enough for lifecycle operations to run.
     *
     * @return  bool  True when the trust tables, the release trust columns and the stored `ready`
     *          lifecycle state are all in place; false while an install or upgrade is mid-migration.
     *
     * @since   2.0.0
     */
    public function lifecycleReady(): bool;

    /**
     * List every trust key on record, revoked and expired ones included.
     *
     * Intended for administration listings and for deciding whether a named key is still active, so an
     * implementation is not required to expose the public key material here.
     *
     * @return  list<array<string, mixed>>  One row per key, each carrying at least `key_id`, `enabled`,
     *          `revoked_at` and `expires_at`.
     *
     * @since   2.0.0
     */
    public function all(): array;

    /**
     * Insert a trust key record.
     *
     * `TrustStore` assembles and validates the row, so an implementation stores it as given rather than
     * re-deriving or re-checking any field.
     *
     * @param   array<string, mixed>  $key  Complete key row as assembled by `TrustStore`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(array $key): void;

    /**
     * Withdraw a key and stamp who revoked it, when, and why.
     *
     * Only a key that is still enabled and not already revoked may be withdrawn; an implementation
     * refuses rather than reporting success for a no-op update.
     *
     * @param   string             $keyId    Identifier of the key to withdraw.
     * @param   string             $actorId  Actor credited with the revocation on the key record.
     * @param   string             $reason   Operator explanation stored alongside the revocation.
     * @param   DateTimeImmutable  $at       Instant recorded as the revocation time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function revoke(string $keyId, string $actorId, string $reason, DateTimeImmutable $at): void;

    /**
     * Locks and returns the trust generation for the current transaction.
     *
     * Every mutation and every runtime trust check takes this lock before reading anything else, which
     * is what serializes competing lifecycle transactions against one another.
     *
     * @return  int  The generation in force for as long as the lock is held.
     *
     * @since   2.0.0
     */
    public function lockGeneration(): int;

    /**
     * Bump the trust generation so that anything holding an earlier value knows its view is stale.
     *
     * @param   DateTimeImmutable  $at  Instant recorded as the generation's update time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function advanceGeneration(DateTimeImmutable $at): void;

    /**
     * Look up the key that may sign for one extension at one instant.
     *
     * The whole usability test belongs here, not just the identifier match: the key has to be enabled,
     * unrevoked, unexpired at the given instant, and its vendor namespace and extension pattern have to
     * admit the extension. Anything short of that reads as absent rather than as an error.
     *
     * @param   string             $keyId                Key identifier named by the package signature.
     * @param   string             $extensionIdentifier  `vendor/name` the signature claims to cover.
     * @param   DateTimeImmutable  $at                   Instant expiry is measured against.
     *
     * @return  array<string, mixed>|null  Key row carrying `public_key_base64`, or null when no key is
     *          usable for this extension.
     *
     * @since   2.0.0
     */
    public function usable(string $keyId, string $extensionIdentifier, DateTimeImmutable $at): ?array;

    /**
     * Fetch the trust record of the release currently installed for an extension.
     *
     * @param   string  $extensionIdentifier  `vendor/name` of the installed extension.
     *
     * @return  array<string, mixed>|null  Release row carrying its manifest, package and deployment
     *          digests, signing key, signature and trust state; null when nothing is installed.
     *
     * @since   2.0.0
     */
    public function installedRelease(string $extensionIdentifier): ?array;

    /**
     * List the identifiers of every extension currently marked active.
     *
     * @return  list<string>  `vendor/name` identifiers of active extensions.
     *
     * @since   2.0.0
     */
    public function activeExtensions(): array;

    /**
     * List the active extensions whose installed release was signed by one key.
     *
     * This is the blast radius of an emergency revocation: exactly these extensions stop being allowed
     * to run when that key is withdrawn without an upgrade path.
     *
     * @param   string  $keyId  Identifier of the signing key.
     *
     * @return  list<string>  `vendor/name` identifiers, empty when the key signs nothing active.
     *
     * @since   2.0.0
     */
    public function activeExtensionsForKey(string $keyId): array;

    /**
     * List the extensions still depending on a key, whatever status they are in.
     *
     * Wider than `activeExtensionsForKey()`: every extension whose installed release names the key
     * counts, except those already quarantined or awaiting reverification. That is what makes an
     * orderly revocation refuse while any installation would otherwise be stranded.
     *
     * @param   string  $keyId  Identifier of the key being retired.
     *
     * @return  list<string>  Current releases that must be upgraded or quarantined before final revocation.
     *
     * @since   2.0.0
     */
    public function extensionsRequiringKey(string $keyId): array;

    /**
     * Quarantine every active extension signed by a key, and report which ones moved.
     *
     * @param   string             $keyId  Identifier of the key being revoked under emergency.
     * @param   DateTimeImmutable  $at     Instant recorded on each extension as its status changed.
     *
     * @return  list<string>  Identifiers that were active and are now quarantined.
     *
     * @since   2.0.0
     */
    public function quarantineExtensionsForKey(string $keyId, DateTimeImmutable $at): array;

    /**
     * Move one active extension into quarantine.
     *
     * The boolean answer is what stops a repeatedly failing extension from bumping the trust generation
     * on every request: only a transition out of the active status counts as a change.
     *
     * @param   string             $extensionIdentifier  `vendor/name` of the extension to withdraw.
     * @param   DateTimeImmutable  $at                   Instant recorded as the status change time.
     *
     * @return  bool  True when the extension was active and is now quarantined; false when it was
     *          already in some other status and nothing was written.
     *
     * @since   2.0.0
     */
    public function quarantineExtension(string $extensionIdentifier, DateTimeImmutable $at): bool;
}
