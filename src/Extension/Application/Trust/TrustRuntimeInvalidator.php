<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

/**
 * Port trust operations use to retire the compiled runtime map their change has just invalidated.
 *
 * Revoking a key or quarantining a release changes what is allowed to run, so the signed runtime
 * publication the request path reads has to be superseded in the same breath. The three methods split
 * that along the durability boundary: `advance()` publishes the new generation inside the caller's
 * transaction so the invalidation commits with the change that caused it, `materialize()` brings the
 * replica's local copy up to it once that commit has landed, and `discardLocal()` throws local state
 * away so it is rebuilt from authority. `TrustStore` depends on this port rather than on the runtime
 * package; `ExtensionRuntimeMapCompiler` is the implementation wired in production.
 *
 * @since  2.0.0
 */
interface TrustRuntimeInvalidator
{
    /**
     * Record authoritative runtime invalidation inside the caller's transaction.
     *
     * The caller owns the transaction so that the registry change and the publication describing it
     * either both land or neither does.
     *
     * @param   string   $reason               Why the runtime is being invalidated, recorded with the
     *          publication; for example `extension.trust_key.revoke`.
     * @param   ?string  $extensionIdentifier  Extension the invalidation is attributed to, or null when
     *          the change is registry-wide.
     *
     * @return  int  The generation this invalidation published.
     *
     * @since   2.0.0
     */
    public function advance(string $reason, ?string $extensionIdentifier = null): int;

    /**
     * Materialize the authoritative generation locally after commit.
     *
     * Called once the transaction that invalidated the runtime has committed, because a generation that
     * a rollback could still erase must not be written to a replica that serves requests from it.
     *
     * @return  int  The generation now in force for this replica.
     *
     * @since   2.0.0
     */
    public function materialize(): int;

    /**
     * Discard only replica-local state so it can be rebuilt from authoritative storage.
     *
     * Authoritative storage is left untouched, which makes this the repair path for a replica whose own
     * copy of the map is corrupt or was written by a build that is no longer trusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function discardLocal(): void;
}
