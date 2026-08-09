<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;

/**
 * Stable identity of the process that materializes and leases an extension runtime generation.
 *
 * Every replica records which generation it loaded in `extension_runtime_materializations`, keyed by
 * the derived lease. Retirement of an old runtime tree waits for those leases to expire, so the four
 * identity values must stay constant for the life of the process: a value that varies per request
 * would multiply lease rows and keep retired trees on disk forever. Hashing the four parts keeps the
 * key a fixed 64 characters no matter how long the operator's deployment and replica names are.
 *
 * @since  2.0.0
 */
final readonly class RuntimeIdentity
{
    /**
     * Lease key this process claims its runtime materialization under.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $leaseId;

    /**
     * Derive the lease key from the four identity values, rejecting anything that is not explicit.
     *
     * @param   string  $deploymentId  Stable name of the deployment this process belongs to.
     * @param   string  $replicaId     Stable name of the replica, distinguishing peers within the deployment.
     * @param   string  $processId     Stable name of the process within the replica.
     * @param   string  $instanceId    Stable name of the instance served; the default keeps lease keys
     *          working for deployments that never set one.
     *
     * @throws  InvalidArgumentException  When any value is not an alphanumeric identifier of 3 to 128
     *          characters bounded by alphanumerics.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $deploymentId,
        public string $replicaId,
        public string $processId,
        public string $instanceId = 'legacy-instance',
    ) {
        foreach ([$deploymentId, $replicaId, $processId, $instanceId] as $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,126}[A-Za-z0-9]$/D', $value) !== 1) {
                throw new InvalidArgumentException('Runtime identity values must be explicit stable identifiers.');
            }
        }

        $this->leaseId = hash('sha256', implode("\0", [$deploymentId, $replicaId, $processId, $instanceId]));
    }
}
