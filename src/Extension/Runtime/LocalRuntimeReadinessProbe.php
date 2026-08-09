<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Kumwe\CMS\Infrastructure\Persistence\ReadinessStatus;

/**
 * Readiness signal for the HTTP request path, answered from the replica-local readiness marker alone.
 *
 * The readiness endpoint is polled continuously by the load balancer, so it must not re-verify runtime
 * authority itself: recomputing artifact digests and querying the registry on every poll would cost
 * more than serving a page. `extension:runtime:watch` does that work out of band and refreshes a
 * signed `.ready` marker beside the runtime map; this probe only checks that the marker verifies
 * against the active key ring, agrees with the generation loaded locally, and is recent. Use
 * `ReadinessProbe` instead where a full database and authority check is wanted.
 *
 * @since  2.0.0
 */
final readonly class LocalRuntimeReadinessProbe implements ReadinessStatus
{
    /**
     * Bind the probe to the compiler that owns the local readiness marker.
     *
     * @param  ExtensionRuntimeMapCompiler  $runtime            Compiler that reads and verifies the marker.
     * @param  int                          $maximumAgeSeconds  How long a marker stays acceptable after the
     *         watcher last refreshed it; must be positive.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionRuntimeMapCompiler $runtime,
        private int $maximumAgeSeconds = 30,
    ) {
    }

    /**
     * Report whether this replica is serving a recently verified runtime generation.
     *
     * @return  bool  False when the marker is absent, unsigned, stale, or disagrees with the generation
     *          loaded locally, so a replica that has drifted is taken out of rotation.
     *
     * @throws  \InvalidArgumentException  When the probe was configured with a non-positive maximum age.
     *
     * @since   2.0.0
     */
    public function ready(): bool
    {
        return $this->runtime->localMarkerFresh($this->maximumAgeSeconds);
    }
}
