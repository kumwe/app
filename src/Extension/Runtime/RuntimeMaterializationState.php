<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

/**
 * Immutable record of which runtime publication generation a replica holds, and whether it is trusted.
 *
 * `ExtensionRuntimeMapCompiler` returns one of these from every read or write of replica-local runtime
 * state, and the container shares the instance captured at boot as the generation this process serves.
 * It is deliberately a value and not a live view: the compiler's `isCurrent()`, `matchesAuthority()`
 * and `assertLoadedGenerationCurrent()` take it back later to hold what a long-lived process loaded
 * against what local disk and the registry now say, which is how a queue worker or the scheduler
 * discovers it is still executing a superseded generation. Every way of failing to read local state is
 * expressed as the `unavailable()` value rather than as an exception, so `$trusted` is the single flag
 * callers branch on.
 *
 * @since  2.0.0
 */
final readonly class RuntimeMaterializationState
{
    /**
     * Capture the generation a replica holds together with the evidence that verified it.
     *
     * @param  string                       $replicaId            Lease key of the replica the state
     *         describes, as derived by `RuntimeIdentity`.
     * @param  int                          $generation           Publication generation held, or -1 when
     *         local disk holds nothing usable.
     * @param  string                       $publicationChecksum  SHA-256 the publication document
     *         records itself under; empty when unavailable.
     * @param  string                       $trustHmac            HMAC the publication carries over its
     *         generation and checksum; empty when unavailable.
     * @param  bool                         $trusted              Whether the generation verified and may
     *         be served from.
     * @param  ?VerifiedRuntimePublication  $publication          Verified publication document, or null
     *         when no trusted document was read.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $replicaId,
        public int $generation,
        public string $publicationChecksum,
        public string $trustHmac,
        public bool $trusted,
        public ?VerifiedRuntimePublication $publication = null,
    ) {
    }

    /**
     * Build the state that says this replica holds no runtime publication it may serve.
     *
     * `ExtensionRuntimeMapCompiler::inspectLocal()` answers with this for every way local state can fail
     * — files absent, JSON unreadable, a marker that does not sign the bytes read, a signature from a
     * key the ring no longer holds — so a caller never has to tell a missing map from a corrupt one.
     * Both are simply untrusted, and materialization rewrites them alike.
     *
     * @param   string  $replicaId  Lease key of the replica that has nothing to serve.
     *
     * @return  self  An untrusted state carrying generation -1, empty checksums and no publication.
     *
     * @since   2.0.0
     */
    public static function unavailable(string $replicaId): self
    {
        return new self($replicaId, -1, '', '', false);
    }
}
